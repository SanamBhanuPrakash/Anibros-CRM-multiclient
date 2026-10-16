<?php
declare(strict_types=1);

namespace App\Modules\Analytics;

use App\Core\Ctx;
use App\Core\Etag;
use App\Core\RawReply;
use App\Core\Reply;
use App\Core\Router;
use App\Core\V;

/** /api/v1/attribution, /api/v1/spend, /api/v1/reports */
final class AnalyticsRoutes
{
    private static function day(): V
    {
        return V::str()->regex('/^\d{4}-\d{2}-\d{2}$/', 'Use a date like 2026-10-14');
    }

    /** @return array<string,V> */
    private static function range(): array
    {
        return ['from' => self::day()->optional(), 'to' => self::day()->optional(), 'includeTest' => V::enum(['true', 'false'])->optional()];
    }

    /** The shared range arguments of a request, as the services want them. */
    private static function rangeArgs(array $q): array
    {
        return ['from' => $q['from'] ?? null, 'to' => $q['to'] ?? null, 'includeTest' => ($q['includeTest'] ?? 'false') === 'true'];
    }

    public static function register(): void
    {
        $page = ['limit' => V::coerceInt()->min(1)->max(200)->default(50), 'cursor' => V::str()->max(512)->optional()];
        $stepKeys = Cohort::stepKeys();
        $text = static fn(int $max) => V::str()->trim()->max($max);

        Router::mount('/api/v1/attribution', [
            ['method' => 'GET', 'path' => '/', 'perm' => 'attribution:read',
                'query' => V::obj(self::range() + ['model' => V::enum(['first', 'last'])->default('first')]),
                'handler' => fn(Ctx $c) => AttributionService::report($c->actor, self::rangeArgs($c->query) + ['model' => AttributionService::assertModel($c->query['model'])])],
            ['method' => 'GET', 'path' => '/leads', 'perm' => 'attribution:read',
                'query' => V::obj($page + self::range() + [
                    'model' => V::enum(['first', 'last'])->default('first'), 'metric' => V::enum($stepKeys), 'source' => V::enum(Channels::SOURCES)->optional(),
                    'campaign' => $text(200)->optional(), 'noCampaign' => V::enum(['true'])->optional(),
                ]),
                'handler' => function (Ctx $c) {
                    $q = $c->query;
                    $r = AttributionService::drill($c->actor, self::rangeArgs($q) + [
                        'model' => AttributionService::assertModel($q['model']), 'metric' => $q['metric'], 'source' => $q['source'] ?? null, 'campaign' => $q['campaign'] ?? null,
                        'noCampaign' => ($q['noCampaign'] ?? null) === 'true', 'limit' => $q['limit'], 'cursor' => $q['cursor'] ?? null,
                    ]);
                    return new Reply($r['items'], 200, $r['page'], ['X-Total-Count' => (string) $r['total']]);
                }],
            ['method' => 'GET', 'path' => '/channels', 'perm' => 'attribution:read', 'handler' => fn(Ctx $c) => AttributionService::mappings($c->actor)],
            ['method' => 'PUT', 'path' => '/channels/{source}', 'perm' => 'attribution:manage', 'params' => V::obj(['source' => V::enum(Channels::SOURCES)]), 'status' => 200,
                'body' => V::obj(['channel' => V::enum(Channels::channelKeys())])->strict(),
                'handler' => fn(Ctx $c) => new Reply(AttributionService::setMapping($c->actor, $c->params['source'], $c->body['channel']), 200)],
        ]);

        $spendEtag = static fn(array $s) => Etag::make((int) $s['version']);
        $amount = static fn() => V::num()->min(0)->max(999999999999);
        Router::mount('/api/v1/spend', [
            ['method' => 'GET', 'path' => '/', 'perm' => 'attribution:read', 'query' => V::obj(['from' => self::day()->optional(), 'to' => self::day()->optional()]),
                'handler' => fn(Ctx $c) => SpendService::list($c->actor, $c->query)],
            ['method' => 'POST', 'path' => '/', 'perm' => 'attribution:manage', 'idempotent' => true,
                'body' => V::obj(['source' => V::enum(Channels::SOURCES), 'campaign' => $text(150)->nullish(), 'periodStart' => self::day(), 'periodEnd' => self::day(), 'amount' => $amount(), 'note' => $text(300)->nullish()]),
                'handler' => function (Ctx $c) use ($spendEtag) { $s = SpendService::create($c->actor, $c->body); return new Reply($s, 201, null, $spendEtag($s)); }],
            ['method' => 'POST', 'path' => '/import', 'perm' => 'attribution:manage', 'status' => 200, 'bodyLimit' => 1024 * 1024,
                'body' => V::obj(['csv' => V::str()->min(1)->max(900000), 'dryRun' => V::bool()->default(false)]),
                'handler' => fn(Ctx $c) => new Reply(SpendService::importCsv($c->actor, $c->body), 200)],
            ['method' => 'PATCH', 'path' => '/{id}', 'perm' => 'attribution:manage', 'params' => V::obj(['id' => V::uuid()]),
                'body' => V::obj(['campaign' => $text(150)->nullish(), 'periodStart' => self::day()->optional(), 'periodEnd' => self::day()->optional(), 'amount' => $amount()->optional(), 'note' => $text(300)->nullish()])->strict(),
                'handler' => function (Ctx $c) use ($spendEtag) {
                    $s = SpendService::update($c->actor, $c->params['id'], $c->body, Etag::ifMatchVersion($c->req->header('if-match')));
                    return new Reply($s, 200, null, $spendEtag($s));
                }],
            ['method' => 'DELETE', 'path' => '/{id}', 'perm' => 'attribution:manage', 'params' => V::obj(['id' => V::uuid()]), 'status' => 204,
                'handler' => function (Ctx $c) { SpendService::remove($c->actor, $c->params['id']); return new Reply(null, 204); }],
        ]);

        Router::mount('/api/v1/reports', [
            ['method' => 'GET', 'path' => '/overview', 'perm' => 'reports:read', 'query' => V::obj(self::range()), 'handler' => fn(Ctx $c) => ReportsService::overview($c->actor, self::rangeArgs($c->query))],
            ['method' => 'GET', 'path' => '/flow', 'perm' => 'reports:read', 'query' => V::obj(self::range()), 'handler' => fn(Ctx $c) => ReportsService::flow($c->actor, self::rangeArgs($c->query))],
            ['method' => 'GET', 'path' => '/leads', 'perm' => 'reports:read',
                'query' => V::obj($page + self::range() + ['metric' => V::enum($stepKeys), 'owner' => V::union([V::uuid(), V::literal('unassigned')])->optional()]),
                'handler' => function (Ctx $c) {
                    $q = $c->query;
                    $r = ReportsService::leads($c->actor, self::rangeArgs($q) + ['metric' => $q['metric'], 'owner' => $q['owner'] ?? null, 'limit' => $q['limit'], 'cursor' => $q['cursor'] ?? null]);
                    return new Reply($r['items'], 200, $r['page'], ['X-Total-Count' => (string) $r['total']]);
                }],
            ['method' => 'GET', 'path' => '/export', 'perm' => 'reports:export', 'query' => V::obj(self::range()),
                'handler' => function (Ctx $c) {
                    $q = $c->query;
                    $r = ReportsService::exportCsv($c->actor, self::rangeArgs($q));
                    $name = 'report-' . ($q['from'] ?? 'last-30-days') . (isset($q['to']) ? '-to-' . $q['to'] : '') . '.csv';
                    return new RawReply($r['csv'], 'text/csv; charset=utf-8', ['Content-Disposition' => 'attachment; filename="' . $name . '"', 'X-Row-Count' => (string) $r['rows'], 'Cache-Control' => 'no-store']);
                }],
        ]);
    }
}
