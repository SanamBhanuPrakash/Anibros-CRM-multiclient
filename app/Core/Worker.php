<?php
declare(strict_types=1);

namespace App\Core;

use App\Modules\Leads\ImportService;
use App\Modules\Automation\AutomationEngine;
use App\Modules\Inbound\InboundProcessor;
use App\Modules\Outbound\OutboundProcessor;
use App\Modules\Messaging\MessagingService;

/** Shared-host-safe job runner. Cron invokes bin/worker.php every minute; web requests may pump briefly after responding. */
final class Worker
{
    /** @return array{claimed:int,succeeded:int,retried:int,dead:int} */
    public static function pump(float $seconds = 55.0, int $maxJobs = 100): array
    {
        $seconds = max(1.0, min(55.0, $seconds));
        // A cursor-backed outbox bridge turns domain events into durable jobs before claims begin.
        // It is bounded so a shared-host cron invocation remains within its time budget.
        AutomationEngine::dispatchEvents(200);
        OutboundProcessor::dispatchEvents(200);
        $result = Jobs::run(self::handlers(), $maxJobs, $seconds);
        InboundProcessor::reconcileDeadJobs();
        self::heartbeat();
        return $result;
    }

    /** @return array<string,callable(array,JobContext):mixed> */
    private static function handlers(): array
    {
        return [
            'mail.send' => [Mailer::class, 'handle'],
            'leads.import' => [ImportService::class, 'handle'],
            'inbound.process' => [InboundProcessor::class, 'handle'],
            'webhook.deliver' => [OutboundProcessor::class, 'handle'],
            'message.send' => [MessagingService::class, 'handle'],
            'automation.dispatch' => [AutomationEngine::class, 'dispatch'],
            'automation.retry' => [AutomationEngine::class, 'retry'],
        ];
    }

    private static function heartbeat(): void
    {
        try {
            Db::system()->tx(static function (Tx $tx): void {
                $tx->run("INSERT INTO system_health (id, component, status, latency_ms, last_checked_at, details, tenant_id) VALUES (?, 'worker', 'healthy', 0, NOW(3), ?, NULL) ON DUPLICATE KEY UPDATE status = 'healthy', latency_ms = 0, last_checked_at = NOW(3), details = VALUES(details)", [Ids::uuid(), Json::enc(['at' => Time::iso(Time::now()), 'host' => gethostname()])]);
            });
        } catch (\Throwable $e) {
            Logger::warn('Worker heartbeat could not be recorded', ['error' => $e->getMessage()]);
        }
    }
}
