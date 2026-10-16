<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Audit trail writer. Runs INSIDE the business transaction so "action happened" and "action was recorded" are atomic; if the audit
 * insert fails the business change rolls back (mandatory-audit semantics). Tenant, actor, IP and correlation id come from the
 * transaction context, so callers cannot forge them.
 */
final class Audit
{
    public static function log(Tx $tx, string $action, string $entityType, string $entityId, mixed $before = null, mixed $after = null): void
    {
        $tx->insert('audit_logs', [
            'tenant_id' => $tx->tenantId,
            'actor_id' => $tx->userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_state' => $before === null ? null : Json::enc(Redact::data($before)),
            'after_state' => $after === null ? null : Json::enc(Redact::data($after)),
            'ip_address' => $tx->ip,
            'correlation_id' => $tx->requestId,
        ]);
    }
}
