<?php
declare(strict_types=1);

namespace App\Modules\Leads;

use App\Core\Actor;

final class Visibility
{
    /**
     * Own-scope visibility for lead aggregates: sales agents only ever count their own leads, plus the ones they were added to as a handler.
     * Returns an SQL predicate on alias `$alias` (appending its positional parameters to $params), or false when the actor may not read leads at all.
     *
     * @param array<int,mixed> $params
     */
    public static function lead(Actor $actor, string $alias, array &$params): string|false
    {
        if ($actor->kind === 'system') return 'TRUE';
        $scope = $actor->scopeIfAllowed('leads:read');
        if (!$scope) return false;
        if ($scope === 'all') return 'TRUE';
        $params[] = $actor->userId;
        $params[] = $actor->userId;
        return "($alias.owner_id = ? OR EXISTS (SELECT 1 FROM lead_handlers lh WHERE lh.lead_id = $alias.id AND lh.tenant_id = $alias.tenant_id AND lh.user_id = ?))";
    }
}
