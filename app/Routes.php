<?php
declare(strict_types=1);

namespace App;

/** The one list of modules whose routes make up the API. Order matters only inside a module (specific paths before {id} paths). */
final class Routes
{
    private static bool $done = false;

    public static function registerAll(): void
    {
        if (self::$done) return;
        self::$done = true;
        \App\Modules\Identity\IdentityRoutes::register();
        \App\Modules\Members\MembersRoutes::register();
        \App\Modules\ApiKeys\ApiKeys::register();
        \App\Modules\Workspace\WorkspaceRoutes::register();
        \App\Modules\Contacts\ContactsRoutes::register();
        \App\Modules\CustomFields\CustomFields::register();
        \App\Modules\Leads\LeadsRoutes::register();
        \App\Modules\Tasks\TasksRoutes::register();
        \App\Modules\Inventory\InventoryRoutes::register();
        \App\Modules\Viewings\ViewingsRoutes::register();
        \App\Modules\Deals\DealsRoutes::register();
        \App\Modules\Analytics\AnalyticsRoutes::register();
        \App\Modules\Dashboard\DashboardRoutes::register();
        \App\Modules\Automation\AutomationRoutes::register();
        \App\Modules\Realtime\RealtimeRoutes::register();
        \App\Modules\Agency\AgencyRoutes::register();
        \App\Modules\Monitor\MonitorRoutes::register();
        \App\Modules\Inbound\InboundRoutes::register();
        \App\Modules\Outbound\OutboundRoutes::register();
        \App\Modules\Messaging\MessagingRoutes::register();
        // MODULES-REGISTER-MARKER (modules are appended above this line as they are ported)
    }
}
