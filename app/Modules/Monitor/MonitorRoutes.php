<?php
declare(strict_types=1);

namespace App\Modules\Monitor;

use App\Core\Ctx;
use App\Core\Pagination;
use App\Core\RawReply;
use App\Core\Reply;
use App\Core\Router;
use App\Core\V;

final class MonitorRoutes
{
    public static function register(): void
    {
        $page = [
            'limit' => V::coerceInt()->min(1)->max(200)->default(30),
            'cursor' => V::str()->max(512)->optional(),
        ];
        $iso = V::datetime();
        $auditFilters = [
            'action' => V::str()->trim()->min(1)->max(80)->optional(),
            'entityType' => V::str()->trim()->min(1)->max(60)->optional(),
            'actorId' => V::uuid()->optional(),
            'entityId' => V::uuid()->optional(),
            'from' => clone $iso,
            'to' => clone $iso,
        ];
        $auditFilters['from'] = $auditFilters['from']->optional();
        $auditFilters['to'] = $auditFilters['to']->optional();

        Router::mount('/api/v1/monitor', [
            [
                'method' => 'GET',
                'path' => '/overview',
                'perm' => 'operations:read',
                'handler' => fn(Ctx $c) => MonitorService::overview($c->actor),
            ],
            [
                'method' => 'GET',
                'path' => '/events',
                'perm' => 'operations:read',
                'query' => V::obj($page + [
                    'severity' => V::enum(['info', 'warn', 'error'])->optional(),
                    'component' => V::str()->max(40)->optional(),
                    'q' => V::str()->trim()->max(100)->optional(),
                    'from' => (clone $iso)->optional(),
                    'to' => (clone $iso)->optional(),
                ]),
                'handler' => function (Ctx $c) {
                    $r = MonitorService::events($c->actor, $c->query);
                    return new Reply($r['items'], 200, $r['page']);
                },
            ],
            [
                'method' => 'GET',
                'path' => '/audit',
                'perm' => 'audit:read',
                'query' => V::obj($page + $auditFilters),
                'handler' => function (Ctx $c) {
                    $r = MonitorService::audit($c->actor, $c->query);
                    return new Reply($r['items'], 200, $r['page']);
                },
            ],
            [
                'method' => 'GET',
                'path' => '/audit/facets',
                'perm' => 'audit:read',
                'handler' => fn(Ctx $c) => MonitorService::auditFacets($c->actor),
            ],
            [
                'method' => 'GET',
                'path' => '/audit/export',
                'perm' => 'audit:read',
                'query' => V::obj($auditFilters),
                'handler' => function (Ctx $c) {
                    $r = MonitorService::auditCsv($c->actor, $c->query);
                    return new RawReply(
                        $r['csv'],
                        'text/csv; charset=utf-8',
                        [
                            'Content-Disposition' => 'attachment; filename="audit-trail-' . gmdate('Y-m-d') . '.csv"',
                            'X-Row-Count' => (string) $r['rows'],
                            'Cache-Control' => 'no-store',
                        ]
                    );
                },
            ],
            [
                'method' => 'GET',
                'path' => '/audit/{id}',
                'perm' => 'audit:read',
                'params' => V::obj(['id' => V::uuid()]),
                'handler' => fn(Ctx $c) => MonitorService::auditDetail($c->actor, $c->params['id']),
            ],
        ]);
    }
}
