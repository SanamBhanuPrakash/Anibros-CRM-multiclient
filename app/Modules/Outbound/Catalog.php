<?php
declare(strict_types=1);

namespace App\Modules\Outbound;

final class Catalog
{
    public const EVENTS = [
        'lead.created' => 'A new lead arrives (from any source)',
        'lead.assigned' => 'A lead is given to someone',
        'lead.stage_changed' => 'A lead moves to another stage (including won and lost)',
        'lead.updated' => 'A lead’s details change',
        'lead.deleted' => 'A lead is archived',
        'activity.created' => 'A call, message, visit or note is logged',
        'task.created' => 'A task is created',
        'task.completed' => 'A task is done',
        'contact.created' => 'A contact is added',
    ];

    public const MAX_ENDPOINTS = 10;
    public const MAX_ATTEMPTS = 8;
    public const DISABLE_AFTER_FAILURES = 30;

    public static function aad(string $endpointId): string
    {
        return 'outbound:' . $endpointId;
    }
}
