<?php
declare(strict_types=1);

namespace App\Modules\Dashboard;

use App\Core\Ctx;
use App\Core\Router;
use App\Core\V;
use App\Modules\Home\HomeService;
use App\Modules\Search\SearchService;

/** /api/v1/dashboard, /api/v1/home, /api/v1/search */
final class DashboardRoutes
{
    public static function register(): void
    {
        $includeTest = static fn(Ctx $c) => ($c->query['includeTest'] ?? 'false') === 'true';
        $flag = V::enum(['true', 'false'])->optional();
        $day = static fn() => V::str()->regex('/^\d{4}-\d{2}-\d{2}$/', 'Use a date like 2026-10-14')->optional();

        Router::mount('/api/v1/dashboard', [
            ['method' => 'GET', 'path' => '/overview', 'perm' => 'dashboard:read', 'query' => V::obj(['from' => $day(), 'to' => $day(), 'includeTest' => $flag]),
                'handler' => fn(Ctx $c) => DashboardOverviewService::overview($c->actor, ['from' => $c->query['from'] ?? null, 'to' => $c->query['to'] ?? null, 'includeTest' => $includeTest($c)])],
            ['method' => 'GET', 'path' => '/metrics', 'perm' => 'dashboard:read', 'query' => V::obj(['includeTest' => $flag]),
                'handler' => fn(Ctx $c) => DashboardService::metrics($c->actor, ['includeTest' => $includeTest($c)])],
            ['method' => 'GET', 'path' => '/attribution', 'perm' => 'attribution:read', 'query' => V::obj(['includeTest' => $flag]),
                'handler' => fn(Ctx $c) => DashboardService::attribution($c->actor, ['includeTest' => $includeTest($c)])],
        ]);

        Router::mount('/api/v1/home', [
            ['method' => 'GET', 'path' => '/', 'handler' => fn(Ctx $c) => HomeService::home($c->actor)],
        ]);

        Router::mount('/api/v1/search', [
            ['method' => 'GET', 'path' => '/', 'perm' => 'search:use', 'query' => V::obj(['q' => V::str()->max(100)]), 'handler' => fn(Ctx $c) => SearchService::search($c->actor, $c->query['q'])],
        ]);
    }
}
