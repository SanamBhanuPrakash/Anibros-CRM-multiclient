<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Domain events: the single spine for "something happened".
 *
 *   Events::emit($tx, ...) writes a row to domain_events INSIDE the business transaction (transactional outbox): commit => the event
 *   exists, rollback => it does not. The worker's dispatcher reads events in id order with a per-consumer cursor (at-least-once; consumers
 *   are idempotent). Browsers learn about events through the polling endpoint (Modules\Realtime), which only returns ids and types:
 *   the UI refetches authoritative data through the normal API.
 */
final class Events
{
    /** type => [aggregate, description, permission] */
    public const CATALOG = [
        'lead.created' => ['lead', 'A lead was created (any channel).', 'leads:read'],
        'lead.updated' => ['lead', 'Lead fields changed.', 'leads:read'],
        'lead.deleted' => ['lead', 'Lead was archived (soft-deleted).', 'leads:read'],
        'lead.assigned' => ['lead', 'Lead owner changed.', 'leads:read'],
        'lead.stage_changed' => ['lead', 'Lead moved to another pipeline stage.', 'leads:read'],
        'pipeline.updated' => ['pipeline', 'A pipeline, its stages or its transition rules changed.', 'pipeline:read'],
        'import.updated' => ['import', 'A CSV import changed state or progressed.', 'leads:import'],
        'contact.created' => ['contact', 'A contact was created.', 'contacts:read'],
        'contact.updated' => ['contact', 'Contact fields changed.', 'contacts:read'],
        'contact.deleted' => ['contact', 'A contact was archived.', 'contacts:read'],
        'contact.merged' => ['contact', 'Two contacts were merged.', 'contacts:read'],
        'activity.created' => ['activity', 'A note/call/email/meeting was logged.', 'activities:read'],
        'task.created' => ['task', 'A task was created.', 'tasks:read'],
        'task.updated' => ['task', 'A task changed.', 'tasks:read'],
        'task.deleted' => ['task', 'A task was deleted.', 'tasks:read'],
        'task.completed' => ['task', 'A task was completed.', 'tasks:read'],
        'viewing.scheduled' => ['viewing', 'A viewing was booked.', 'viewings:read'],
        'viewing.updated' => ['viewing', 'A viewing changed (reschedule, status).', 'viewings:read'],
        'project.updated' => ['project', 'A project (development) or its files changed.', 'projects:read'],
        'reservation.created' => ['reservation', 'An offer was recorded on a unit.', 'reservations:read'],
        'reservation.updated' => ['reservation', 'A reservation was made or an open offer or reservation was edited.', 'reservations:read'],
        'reservation.completed' => ['reservation', 'A sale was recorded (deal value tied to a unit).', 'reservations:read'],
        'reservation.closed' => ['reservation', 'An offer or reservation ended without a sale, or a recorded sale was reversed.', 'reservations:read'],
        'shortlist.changed' => ['lead', 'A property was added to, changed on or removed from a buyer’s shortlist.', 'leads:read'],
        'property.created' => ['property', 'A property was created.', 'properties:read'],
        'property.updated' => ['property', 'A property changed.', 'properties:read'],
        'property.status_changed' => ['property', 'Availability status changed.', 'properties:read'],
        'notification.created' => ['notification', 'An in-app notification was created.', 'notifications:read'],
        'lead_source.updated' => ['lead_source', 'A lead source (inbound endpoint) was created, changed, paused or deleted.', 'integrations:read'],
        'inbound.updated' => ['inbound_event', 'A lead-source delivery was received, processed, retried or ignored.', 'integrations:manage'],
        'integration.updated' => ['integration', 'Integration configuration or health changed.', 'integrations:read'],
        'webhook.received' => ['webhook_inbox', 'An inbound provider webhook was durably received.', 'operations:read'],
        'automation.updated' => ['automation', 'An automation was turned on or off, or one of its runs finished.', 'automation:read'],
        'member.invited' => ['member', 'A workspace invitation was sent.', 'members:read'],
        'member.updated' => ['member', 'Membership role/status changed.', 'members:read'],
    ];

    /** Must be called with the transaction of the business operation. */
    public static function emit(Tx $tx, string $type, string $aggregateId, array $payload = []): void
    {
        $meta = self::CATALOG[$type] ?? null;
        if ($meta === null) throw new \InvalidArgumentException("Unknown event type $type");
        $json = $payload ? Json::enc($payload) : '{}';
        if (strlen($json) >= 4096) $json = '{}'; // events carry ids and small facts; an oversized payload is dropped, never the event
        $tx->insert('domain_events', [
            'tenant_id' => $tx->tenantId,
            'type' => $type,
            'aggregate_type' => $meta[0],
            'aggregate_id' => $aggregateId,
            'actor_user_id' => $tx->userId,
            'correlation_id' => $tx->requestId,
            'payload' => $json,
        ]);
    }
}
