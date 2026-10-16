<?php
declare(strict_types=1);

namespace App\Modules\Workspace;

use App\Core\Ctx;
use App\Core\Etag;
use App\Core\Reply;
use App\Core\Router;
use App\Core\V;
use App\Modules\Leads\Routing;

final class WorkspaceRoutes
{
    public static function routingSchema(): V
    {
        return V::arr(V::obj(['source' => V::enum(Routing::SOURCES), 'userIds' => V::arr(V::uuid())->min(1)->max(20)]))->max(9);
    }

    public static function register(): void
    {
        $hex = V::str()->regex('/^#[0-9a-fA-F]{6}$/', 'Use a colour like #1F6F5C');
        Router::mount('/api/v1/workspace', [
            ['method' => 'GET', 'path' => '/', 'perm' => 'tenant:read', 'handler' => function (Ctx $c) {
                $w = WorkspaceService::get($c->actor);
                return new Reply($w, 200, null, Etag::make($w['version']));
            }],
            ['method' => 'PATCH', 'path' => '/', 'perm' => 'tenant:update',
                'body' => V::obj([
                    'name' => V::str()->trim()->min(1)->max(120)->optional(),
                    'timezone' => V::str()->trim()->min(3)->max(64)->optional(),
                    'currency' => V::str()->trim()->regex('/^[A-Za-z]{3}$/', 'Use a 3-letter currency code such as INR')->optional(),
                    'defaultCountry' => V::str()->trim()->regex('/^[A-Za-z]{2}$/', 'Use a 2-letter country code such as IN')->nullable()->optional(),
                    'leadAssignment' => V::enum(WorkspaceService::ASSIGNMENT_MODES)->optional(),
                    'firstResponseMinutes' => V::int()->min(0)->max(10080)->optional(),
                    'routing' => self::routingSchema()->optional(),
                    'primaryColor' => (clone $hex)->nullable()->optional(),
                    'accentColor' => (clone $hex)->nullable()->optional(),
                ])->strict(),
                'handler' => function (Ctx $c) {
                    $w = WorkspaceService::update($c->actor, $c->body, Etag::ifMatchVersion($c->req->header('if-match')));
                    return new Reply($w, 200, null, Etag::make($w['version']));
                }],
        ]);
    }
}
