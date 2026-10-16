<?php
declare(strict_types=1);

namespace App\Core;

final class PermanentJobError extends \RuntimeException
{
}

/** Throw to reschedule at a specific delay (e.g. provider Retry-After). */
final class RetryAfterError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $delaySeconds)
    {
        parent::__construct($message);
    }
}

/**
 * Durable job queue on MySQL with leases (the PostgreSQL SKIP LOCKED queue, rebuilt for MySQL/MariaDB without SKIP LOCKED).
 *
 *   Jobs::enqueue() runs INSIDE the caller's transaction (outbox semantics): the job exists iff the business change committed.
 *   Jobs::run()     claims jobs with one atomic UPDATE ... ORDER BY ... LIMIT stamped with a unique worker token, runs the handlers,
 *                   completes / fails with ownership checks, retries with exponential backoff + jitter, dead-letters after
 *                   max_attempts or on PermanentJobError, and reaps jobs whose worker died (lease expired).
 * Semantics: at-least-once. Handlers MUST be idempotent. No database transaction stays open while a handler runs.
 * Driven by bin/worker.php (cPanel cron, every minute) and opportunistically after web responses.
 */
final class Jobs
{
    /**
     * @param array{type:string,payload?:array,tenantId?:string|null|false,delaySeconds?:int,priority?:int,maxAttempts?:int,dedupeKey?:?string} $job
     *   tenantId: omitted/false = the transaction's workspace; null = platform job (worker only); string = explicit workspace
     * @return string|null job id, null when deduplicated
     */
    public static function enqueue(Tx $tx, array $job): ?string
    {
        $tenant = array_key_exists('tenantId', $job) && $job['tenantId'] !== false ? $job['tenantId'] : $tx->tenantId;
        $delay = max(0, (int) ($job['delaySeconds'] ?? 0));
        $id = $tx->insertIgnore('jobs', [
            'tenant_id' => $tenant,
            'type' => $job['type'],
            'payload' => Json::encObj($job['payload'] ?? []),
            'run_at' => $delay > 0 ? new Raw('DATE_ADD(NOW(3), INTERVAL ' . $delay . ' SECOND)') : new Raw('NOW(3)'),
            'priority' => (int) ($job['priority'] ?? 0),
            'max_attempts' => (int) ($job['maxAttempts'] ?? 8),
            'dedupe_key' => $job['dedupeKey'] ?? null,
            'correlation_id' => $tx->requestId,
        ]);
        return $id ?: null;
    }

    public static function backoffSeconds(int $attempt): int
    {
        $cap = min(3600, 5 * (2 ** max(0, $attempt)));
        return max(1, random_int(1, (int) $cap));
    }

    /**
     * Process queued jobs until nothing is runnable or the budget is spent.
     *
     * @param array<string,callable(array,JobContext):mixed> $handlers type => handler(job, context)
     * @return array{claimed:int,succeeded:int,retried:int,dead:int}
     */
    public static function run(array $handlers, int $maxJobs = 20, float $maxSeconds = 20.0, int $leaseSeconds = 120): array
    {
        $stats = ['claimed' => 0, 'succeeded' => 0, 'retried' => 0, 'dead' => 0];
        $deadline = microtime(true) + $maxSeconds;
        self::reap();
        while ($stats['claimed'] < $maxJobs && microtime(true) < $deadline) {
            $batch = self::claim(min(5, $maxJobs - $stats['claimed']), $leaseSeconds);
            if (!$batch['jobs']) break;
            foreach ($batch['jobs'] as $job) {
                $stats['claimed']++;
                $r = self::execute($job, $batch['token'], $handlers, $leaseSeconds);
                $stats[$r]++;
                if (microtime(true) >= $deadline) break;
            }
        }
        return $stats;
    }

    /** @return array{token:string,jobs:array<int,array<string,mixed>>} */
    public static function claim(int $limit, int $leaseSeconds): array
    {
        $token = substr((string) gethostname(), 0, 30) . ':' . getmypid() . ':' . Ids::hex(4);
        $jobs = Db::system()->tx(static function (Tx $tx) use ($token, $limit, $leaseSeconds) {
            $tx->run(
                "UPDATE jobs SET status = 'running', locked_by = ?, attempts = attempts + 1, lease_until = DATE_ADD(NOW(3), INTERVAL ? SECOND)
                  WHERE status = 'queued' AND run_at <= NOW(3) ORDER BY priority DESC, run_at LIMIT ?",
                [$token, $leaseSeconds, $limit]
            );
            return $tx->all("SELECT id, tenant_id, type, payload, attempts, max_attempts, correlation_id FROM jobs WHERE locked_by = ? AND status = 'running'", [$token]);
        }, ['retries' => 3]);
        return ['token' => $token, 'jobs' => $jobs];
    }

    private static function execute(array $row, string $token, array $handlers, int $leaseSeconds): string
    {
        $job = [
            'id' => $row['id'], 'tenantId' => $row['tenant_id'], 'type' => $row['type'], 'payload' => Json::dec($row['payload'], []),
            'attempts' => (int) $row['attempts'], 'maxAttempts' => (int) $row['max_attempts'], 'correlationId' => $row['correlation_id'],
        ];
        $handler = $handlers[$job['type']] ?? null;
        if (!$handler) {
            self::finish($job, $token, 'dead', 'No handler registered for job type "' . $job['type'] . '"');
            return 'dead';
        }
        $ctx = new JobContext($job);
        try {
            $result = $handler($job, $ctx);
            self::finish($job, $token, 'done', null, $result);
            return 'succeeded';
        } catch (\Throwable $e) {
            $msg = mb_substr($e->getMessage(), 0, 2000);
            if ($e instanceof PermanentJobError || $job['attempts'] >= $job['maxAttempts']) {
                Logger::error('Job failed permanently', ['jobId' => $job['id'], 'type' => $job['type'], 'error' => $msg]);
                EventLog::record(['tenantId' => $job['tenantId'], 'component' => 'worker', 'severity' => 'error', 'message' => 'Background job "' . $job['type'] . '" failed permanently', 'context' => ['jobId' => $job['id'], 'jobType' => $job['type'], 'attempts' => $job['attempts'], 'error' => $msg], 'correlationId' => $job['correlationId']]);
                self::finish($job, $token, 'dead', $msg);
                return 'dead';
            }
            $delay = $e instanceof RetryAfterError ? $e->delaySeconds : self::backoffSeconds($job['attempts']);
            Logger::warn('Job failed; will retry', ['jobId' => $job['id'], 'type' => $job['type'], 'error' => $msg, 'retryInSeconds' => $delay]);
            self::finish($job, $token, 'queued', $msg, null, $delay);
            return 'retried';
        }
    }

    private static function finish(array $job, string $token, string $status, ?string $error = null, mixed $result = null, int $delay = 0): void
    {
        Db::system()->tx(static function (Tx $tx) use ($job, $token, $status, $error, $result, $delay) {
            $n = $tx->run(
                "UPDATE jobs SET status = ?, last_error = ?, result = ?,
                        run_at = CASE WHEN ? = 'queued' THEN DATE_ADD(NOW(3), INTERVAL ? SECOND) ELSE run_at END,
                        finished_at = CASE WHEN ? IN ('done','dead') THEN NOW(3) ELSE NULL END, lease_until = NULL, locked_by = NULL
                  WHERE id = ? AND locked_by = ? AND status = 'running'",
                [$status, $error, $result === null ? null : Json::enc($result), $status, $delay, $status, $job['id'], $token]
            );
            if ($n === 0) Logger::warn('Job completion ignored: lease no longer owned', ['jobId' => $job['id']]);
        });
    }

    /** Return jobs whose worker vanished (lease expired) to the queue, or dead-letter them when out of attempts. */
    public static function reap(): int
    {
        return Db::system()->tx(static fn(Tx $tx) => $tx->run(
            "UPDATE jobs SET status = CASE WHEN attempts >= max_attempts THEN 'dead' ELSE 'queued' END,
                    last_error = 'lease expired (worker lost)', run_at = NOW(3), lease_until = NULL, locked_by = NULL,
                    finished_at = CASE WHEN attempts >= max_attempts THEN NOW(3) ELSE NULL END
              WHERE status = 'running' AND lease_until < NOW(3)"
        ));
    }
}

/** What a job handler gets besides the job: a tenant-scoped transaction for the job's workspace. */
final class JobContext
{
    /** @param array<string,mixed> $job */
    public function __construct(private array $job)
    {
    }

    /**
     * @template T
     * @param callable(Tx):T $fn
     * @return T
     */
    public function withTenant(callable $fn): mixed
    {
        if (!$this->job['tenantId']) throw new \LogicException('Platform job has no workspace context');
        return Db::tenant(['tenantId' => $this->job['tenantId'], 'requestId' => $this->job['correlationId']])->tx($fn);
    }

    public function tenantId(): ?string
    {
        return $this->job['tenantId'];
    }
}
