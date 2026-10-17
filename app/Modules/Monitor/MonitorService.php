<?php
declare(strict_types=1);

namespace App\Modules\Monitor;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Db;
use App\Core\Errors;
use App\Core\Json;
use App\Core\Pagination;
use App\Core\Redact;
use App\Core\Time;
use App\Core\Tx;

final class MonitorService
{
    private const HEARTBEAT_WARN_SECONDS = 45;
    private const HEARTBEAT_FAIL_SECONDS = 120;

    public static function overview(Actor $actor): array
    {
        $actor->assertCan('operations:read');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor): array {
            $started = microtime(true);
            $tx->val('SELECT 1');
            $dbMs = (int) round((microtime(true) - $started) * 1000);
            $tenantId = $actor->tenantId;

            $jobs = $tx->all("SELECT status, COUNT(*) AS n FROM jobs WHERE tenant_id = ? AND status IN ('queued','running') GROUP BY status", [$tenantId]);
            $oldest = (int) ($tx->val("SELECT COALESCE(TIMESTAMPDIFF(SECOND, MIN(run_at), UTC_TIMESTAMP(3)), 0) FROM jobs WHERE tenant_id = ? AND status = 'queued' AND run_at <= UTC_TIMESTAMP(3)", [$tenantId]) ?? 0);
            $dead = (int) ($tx->val("SELECT COUNT(*) FROM jobs WHERE tenant_id = ? AND status = 'dead' AND finished_at > DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 7 DAY)", [$tenantId]) ?? 0);
            $health = $tx->one("SELECT status, TIMESTAMPDIFF(SECOND, last_checked_at, UTC_TIMESTAMP(3)) AS age FROM system_health WHERE component = 'worker' ORDER BY last_checked_at DESC LIMIT 1");
            $sources = $tx->all("SELECT status, COUNT(*) AS n FROM inbound_endpoints WHERE tenant_id = ? AND deleted_at IS NULL GROUP BY status", [$tenantId]);
            $deliveries = $tx->all("SELECT status, COUNT(*) AS n FROM inbound_events WHERE tenant_id = ? AND received_at > DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 24 HOUR) GROUP BY status", [$tenantId]);
            $quiet = $tx->all("SELECT id, name, last_received_at FROM inbound_endpoints WHERE tenant_id = ? AND deleted_at IS NULL AND status = 'active' AND last_received_at IS NOT NULL AND last_received_at < DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 24 HOUR) ORDER BY last_received_at LIMIT 5", [$tenantId]);
            $eventCounts = $tx->all("SELECT severity, component, COUNT(*) AS n FROM operational_logs WHERE tenant_id = ? AND created_at > DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 24 HOUR) AND severity IN ('warn','error','fatal') GROUP BY severity, component", [$tenantId]);
            $recent = $tx->all("SELECT id, created_at, severity, component, message FROM operational_logs WHERE tenant_id = ? AND severity IN ('warn','error','fatal') ORDER BY created_at DESC, id DESC LIMIT 8", [$tenantId]);
            $auditCount = (int) ($tx->val("SELECT COUNT(*) FROM audit_logs WHERE tenant_id = ? AND created_at > DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 24 HOUR)", [$tenantId]) ?? 0);

            $jobCounts = ['queued' => 0, 'running' => 0];
            foreach ($jobs as $row) $jobCounts[$row['status']] = (int) $row['n'];
            $sourceCounts = ['active' => 0, 'paused' => 0];
            foreach ($sources as $row) $sourceCounts[$row['status']] = (int) $row['n'];
            $deliveryCounts = ['received' => 0, 'created' => 0, 'duplicate' => 0, 'needs_review' => 0, 'ignored' => 0, 'failed' => 0];
            foreach ($deliveries as $row) $deliveryCounts[$row['status']] = (int) $row['n'];

            $errors24h = 0;
            $security24h = 0;
            $warnings24h = 0;
            foreach ($eventCounts as $row) {
                $count = (int) $row['n'];
                if (in_array($row['severity'], ['error', 'fatal'], true)) $errors24h += $count;
                if ($row['component'] === 'security') $security24h += $count;
                if ($row['severity'] === 'warn') $warnings24h += $count;
            }

            $leadMetrics = null;
            if ($actor->scopeIfAllowed('leads:read') === 'all') {
                $leadCounts = $tx->one("SELECT SUM(created_at > DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 24 HOUR)) AS d1, COUNT(*) AS d7 FROM leads WHERE tenant_id = ? AND deleted_at IS NULL AND created_at > DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 7 DAY)", [$tenantId]);
                $bySource = $tx->all("SELECT source, COUNT(*) AS n FROM leads WHERE tenant_id = ? AND deleted_at IS NULL AND created_at > DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 7 DAY) GROUP BY source ORDER BY n DESC", [$tenantId]);
                $firstReplyOverdue = (int) ($tx->val("SELECT COUNT(*) FROM leads WHERE tenant_id = ? AND deleted_at IS NULL AND closed_at IS NULL AND first_response_at IS NULL AND first_response_due_at < UTC_TIMESTAMP(3)", [$tenantId]) ?? 0);
                $tasksOverdue = (int) ($tx->val("SELECT COUNT(*) FROM tasks WHERE tenant_id = ? AND deleted_at IS NULL AND status IN ('pending','in_progress') AND due_date < UTC_TIMESTAMP(3)", [$tenantId]) ?? 0);
                $leadMetrics = [
                    'created24h' => (int) ($leadCounts['d1'] ?? 0),
                    'created7d' => (int) ($leadCounts['d7'] ?? 0),
                    'bySource7d' => array_map(static fn(array $r) => ['source' => $r['source'], 'n' => (int) $r['n']], $bySource),
                    'firstReplyOverdue' => $firstReplyOverdue,
                    'tasksOverdue' => $tasksOverdue,
                ];
            }

            $queue = [
                'queued' => $jobCounts['queued'],
                'running' => $jobCounts['running'],
                'deadLast7d' => $dead,
                'oldestWaitingSeconds' => $oldest,
            ];
            $workerAge = $health ? (int) $health['age'] : null;
            $checks = [
                [
                    'id' => 'database',
                    'label' => 'Database',
                    'status' => $dbMs > 1500 ? 'warn' : 'ok',
                    'detail' => $dbMs > 1500 ? "Slow to answer ($dbMs ms)" : "Responding in $dbMs ms",
                ],
                self::workerCheck($workerAge),
                self::queueCheck($queue),
                self::intakeCheck($sourceCounts, $deliveryCounts, $quiet),
                self::errorCheck($errors24h, $warnings24h),
            ];
            $status = 'healthy';
            foreach ($checks as $check) {
                if ($check['status'] === 'fail') { $status = 'problem'; break; }
                if ($check['status'] === 'warn') $status = 'attention';
            }
            $summary = match ($status) {
                'healthy' => 'Everything is running normally.',
                'attention' => 'Something needs a look, but nothing is down.',
                default => 'Something is wrong and may be costing you leads.',
            };

            return [
                'generatedAt' => Time::iso(Time::now()),
                'status' => $status,
                'summary' => $summary,
                'checks' => $checks,
                'queue' => $queue,
                'intake' => [
                    'activeSources' => $sourceCounts['active'],
                    'pausedSources' => $sourceCounts['paused'],
                    'deliveries24h' => $deliveryCounts,
                    'quietSources' => array_map(static fn(array $r) => ['id' => $r['id'], 'name' => $r['name'], 'lastReceivedAt' => Time::iso($r['last_received_at'])], $quiet),
                ],
                'leads' => $leadMetrics,
                'events' => ['errors24h' => $errors24h, 'security24h' => $security24h, 'warnings24h' => $warnings24h],
                'audit' => ['actions24h' => $auditCount],
                'recent' => array_map(static fn(array $r) => ['id' => $r['id'], 'at' => Time::iso($r['created_at']), 'severity' => $r['severity'], 'component' => $r['component'], 'message' => $r['message']], $recent),
            ];
        });
    }

    public static function events(Actor $actor, array $q): array
    {
        $actor->assertCan('operations:read');
        $sig = 'monitor-events:' . hash('sha256', Json::enc([
            'severity' => $q['severity'] ?? null,
            'component' => $q['component'] ?? null,
            'q' => $q['q'] ?? null,
            'from' => isset($q['from']) ? Time::db($q['from']) : null,
            'to' => isset($q['to']) ? Time::db($q['to']) : null,
        ]));
        $cursor = Pagination::decode($q['cursor'] ?? null, $sig);
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $q, $sig, $cursor): array {
            $where = ['l.tenant_id = ?'];
            $params = [$actor->tenantId];
            if (($q['severity'] ?? null) === 'error') $where[] = "l.severity IN ('error','fatal')";
            elseif (($q['severity'] ?? null) === 'warn') $where[] = "l.severity IN ('warn','error','fatal')";
            self::addFilter($where, $params, 'l.component = ?', $q['component'] ?? null);
            if (!empty($q['q'])) {
                $search = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q['q']);
                self::addFilter($where, $params, "l.message LIKE ? ESCAPE '!'", '%' . $search . '%');
            }
            self::addFilter($where, $params, 'l.created_at >= ?', isset($q['from']) ? Time::db($q['from']) : null);
            self::addFilter($where, $params, 'l.created_at < ?', isset($q['to']) ? Time::db($q['to']) : null);
            $predicate = Pagination::predicate($cursor, $params, 'l.created_at', 'l.id', 'desc', 'ts');
            if ($predicate) $where[] = $predicate;
            $params[] = (int) $q['limit'] + 1;
            $rows = $tx->all(
                'SELECT l.id, l.created_at, l.severity, l.component, l.message, l.correlation_id, l.context, u.name AS user_name
                   FROM operational_logs l LEFT JOIN users u ON u.id = l.user_id
                  WHERE ' . implode(' AND ', $where) . ' ORDER BY l.created_at DESC, l.id DESC LIMIT ?',
                $params
            );
            $page = Pagination::page($rows, (int) $q['limit'], $sig, static fn(array $r) => Time::iso($r['created_at']));
            $items = array_map(static function (array $r): array {
                return [
                    'id' => $r['id'],
                    'at' => Time::iso($r['created_at']),
                    'severity' => $r['severity'],
                    'component' => $r['component'],
                    'message' => $r['message'],
                    'requestId' => $r['correlation_id'],
                    'userName' => $r['user_name'],
                    'context' => Json::obj(Redact::data(Json::dec($r['context'], []))),
                ];
            }, $page['items']);
            return ['items' => $items, 'page' => $page['page']];
        });
    }

    public static function audit(Actor $actor, array $q): array
    {
        $actor->assertCan('audit:read');
        $filters = self::auditFilters($q);
        $sig = 'audit:' . hash('sha256', Json::enc($filters));
        $cursor = Pagination::decode($q['cursor'] ?? null, $sig);
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $q, $filters, $sig, $cursor): array {
            [$where, $params] = self::auditWhere($actor, $filters);
            $predicate = Pagination::predicate($cursor, $params, 'a.created_at', 'a.id', 'desc', 'ts');
            if ($predicate) $where[] = $predicate;
            $params[] = (int) $q['limit'] + 1;
            $rows = $tx->all(
                'SELECT a.id, a.created_at, a.action, a.entity_type, a.entity_id, a.actor_id, u.name AS actor_name, a.ip_address, a.correlation_id
                   FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id
                  WHERE ' . implode(' AND ', $where) . ' ORDER BY a.created_at DESC, a.id DESC LIMIT ?',
                $params
            );
            $page = Pagination::page($rows, (int) $q['limit'], $sig, static fn(array $r) => Time::iso($r['created_at']));
            return ['items' => array_map([self::class, 'auditRow'], $page['items']), 'page' => $page['page']];
        });
    }

    public static function auditDetail(Actor $actor, string $id): array
    {
        $actor->assertCan('audit:read');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $id): array {
            $row = $tx->one(
                'SELECT a.id, a.created_at, a.action, a.entity_type, a.entity_id, a.actor_id, u.name AS actor_name, a.ip_address, a.correlation_id, a.before_state, a.after_state
                   FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id
                  WHERE a.id = ? AND a.tenant_id = ?',
                [$id, $actor->tenantId]
            );
            if (!$row) throw Errors::notFound('Audit entry');
            return self::auditRow($row) + [
                'before' => Json::dec($row['before_state'], null) === null ? null : Redact::data(Json::dec($row['before_state'], null)),
                'after' => Json::dec($row['after_state'], null) === null ? null : Redact::data(Json::dec($row['after_state'], null)),
            ];
        });
    }

    public static function auditFacets(Actor $actor): array
    {
        $actor->assertCan('audit:read');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor): array {
            $actions = $tx->col("SELECT DISTINCT action FROM audit_logs WHERE tenant_id = ? AND created_at > DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 90 DAY) ORDER BY action LIMIT 300", [$actor->tenantId]);
            $entityTypes = $tx->col("SELECT DISTINCT entity_type FROM audit_logs WHERE tenant_id = ? AND created_at > DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 90 DAY) ORDER BY entity_type LIMIT 100", [$actor->tenantId]);
            $people = $tx->all(
                "SELECT DISTINCT u.id, u.name FROM audit_logs a JOIN users u ON u.id = a.actor_id
                  WHERE a.tenant_id = ? AND a.created_at > DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 90 DAY)
                  ORDER BY u.name LIMIT 200",
                [$actor->tenantId]
            );
            return ['actions' => $actions, 'entityTypes' => $entityTypes, 'people' => $people];
        });
    }

    public static function auditCsv(Actor $actor, array $filters): array
    {
        $actor->assertCan('audit:read');
        $filters = self::auditFilters($filters);
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $filters): array {
            [$where, $params] = self::auditWhere($actor, $filters);
            $rows = $tx->all(
                'SELECT a.created_at, u.name AS actor_name, a.action, a.entity_type, a.entity_id, a.ip_address, a.correlation_id
                   FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id
                  WHERE ' . implode(' AND ', $where) . ' ORDER BY a.created_at DESC, a.id DESC LIMIT 10000',
                $params
            );
            Audit::log($tx, 'audit.export', 'audit_log', $actor->tenantId, null, ['rows' => count($rows), 'filters' => $filters]);
            $csvRows = array_map(static fn(array $r) => [
                Time::iso($r['created_at']),
                $r['actor_name'] ?? 'System',
                $r['action'],
                $r['entity_type'],
                $r['entity_id'],
                $r['ip_address'],
                $r['correlation_id'],
            ], $rows);
            return ['csv' => \App\Core\Csv::write(['When', 'Who', 'Action', 'Record type', 'Record id', 'IP address', 'Request id'], $csvRows), 'rows' => count($rows)];
        });
    }

    private static function workerCheck(?int $age): array
    {
        if ($age === null) return ['id' => 'worker', 'label' => 'Background worker', 'status' => 'fail', 'detail' => 'No heartbeat yet. Emails, reminders and lead intake are not being processed.'];
        if ($age > self::HEARTBEAT_FAIL_SECONDS) return ['id' => 'worker', 'label' => 'Background worker', 'status' => 'fail', 'detail' => 'Silent for ' . self::formatAge($age) . '. Emails, reminders and lead intake are not being processed.'];
        if ($age > self::HEARTBEAT_WARN_SECONDS) return ['id' => 'worker', 'label' => 'Background worker', 'status' => 'warn', 'detail' => 'Last heartbeat ' . self::formatAge($age) . ' ago'];
        return ['id' => 'worker', 'label' => 'Background worker', 'status' => 'ok', 'detail' => 'Running (heartbeat ' . self::formatAge($age) . ' ago)'];
    }

    private static function queueCheck(array $queue): array
    {
        if ($queue['deadLast7d'] > 0) return ['id' => 'queue', 'label' => 'Job queue', 'status' => 'warn', 'detail' => $queue['deadLast7d'] . ' background ' . ($queue['deadLast7d'] === 1 ? 'job failed' : 'jobs failed') . ' for good in the last 7 days'];
        if ($queue['oldestWaitingSeconds'] > 300) return ['id' => 'queue', 'label' => 'Job queue', 'status' => 'warn', 'detail' => 'Oldest waiting job has been queued for ' . self::formatAge($queue['oldestWaitingSeconds'])];
        return ['id' => 'queue', 'label' => 'Job queue', 'status' => 'ok', 'detail' => $queue['queued'] || $queue['running'] ? $queue['queued'] . ' waiting, ' . $queue['running'] . ' running' : 'Nothing waiting'];
    }

    private static function intakeCheck(array $sources, array $deliveries, array $quiet): array
    {
        $needsAttention = $deliveries['needs_review'] + $deliveries['failed'];
        if ($needsAttention > 0) return ['id' => 'intake', 'label' => 'Lead intake', 'status' => $deliveries['failed'] > 0 ? 'fail' : 'warn', 'detail' => $needsAttention . ' ' . ($needsAttention === 1 ? 'delivery needs' : 'deliveries need') . ' attention. A lead may be waiting.'];
        if ($quiet) return ['id' => 'intake', 'label' => 'Lead intake', 'status' => 'warn', 'detail' => 'No leads from ' . implode(', ', array_column($quiet, 'name')) . ' in over 24 hours'];
        if ($sources['active'] + $sources['paused'] === 0) return ['id' => 'intake', 'label' => 'Lead intake', 'status' => 'ok', 'detail' => 'No lead sources connected yet'];
        $leads = $deliveries['created'] + $deliveries['duplicate'];
        return ['id' => 'intake', 'label' => 'Lead intake', 'status' => 'ok', 'detail' => $sources['active'] . ' active ' . ($sources['active'] === 1 ? 'source' : 'sources') . ', ' . $leads . ' ' . ($leads === 1 ? 'lead' : 'leads') . ' in 24 h'];
    }

    private static function errorCheck(int $errors, int $warnings): array
    {
        if ($errors > 0) return ['id' => 'errors', 'label' => 'Errors', 'status' => $errors >= 10 ? 'fail' : 'warn', 'detail' => $errors . ' ' . ($errors === 1 ? 'error' : 'errors') . ' in the last 24 hours'];
        return ['id' => 'errors', 'label' => 'Errors', 'status' => 'ok', 'detail' => $warnings ? 'None in 24 h (' . $warnings . ' warning' . ($warnings === 1 ? '' : 's') . ')' : 'None in the last 24 hours'];
    }

    private static function formatAge(int $seconds): string
    {
        return $seconds < 90 ? "$seconds s" : ($seconds < 5400 ? round($seconds / 60) . ' min' : round($seconds / 3600) . ' h');
    }

    private static function addFilter(array &$where, array &$params, string $sql, mixed $value): void
    {
        if ($value === null || $value === '') return;
        $where[] = $sql;
        $params[] = $value;
    }

    private static function auditFilters(array $filters): array
    {
        return [
            'action' => $filters['action'] ?? null,
            'entityType' => $filters['entityType'] ?? null,
            'actorId' => $filters['actorId'] ?? null,
            'entityId' => $filters['entityId'] ?? null,
            'from' => isset($filters['from']) ? Time::db($filters['from']) : null,
            'to' => isset($filters['to']) ? Time::db($filters['to']) : null,
        ];
    }

    private static function auditWhere(Actor $actor, array $filters): array
    {
        $where = ['a.tenant_id = ?'];
        $params = [$actor->tenantId];
        if ($filters['action'] !== null) {
            $where[] = "(a.action = ? OR a.action LIKE ? ESCAPE '!')";
            $params[] = $filters['action'];
            $prefix = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['action']);
            $params[] = $prefix . '.%';
        }
        self::addFilter($where, $params, 'a.entity_type = ?', $filters['entityType']);
        self::addFilter($where, $params, 'a.actor_id = ?', $filters['actorId']);
        self::addFilter($where, $params, 'a.entity_id = ?', $filters['entityId']);
        self::addFilter($where, $params, 'a.created_at >= ?', $filters['from']);
        self::addFilter($where, $params, 'a.created_at < ?', $filters['to']);
        return [$where, $params];
    }

    private static function auditRow(array $row): array
    {
        return [
            'id' => $row['id'],
            'at' => Time::iso($row['created_at']),
            'action' => $row['action'],
            'entityType' => $row['entity_type'],
            'entityId' => $row['entity_id'],
            'actorId' => $row['actor_id'],
            'actorName' => $row['actor_name'],
            'ip' => $row['ip_address'],
            'requestId' => $row['correlation_id'],
        ];
    }
}
