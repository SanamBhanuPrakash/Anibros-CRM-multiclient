<?php
declare(strict_types=1);

namespace App\Modules\Messaging;

use App\Core\Ctx;
use App\Core\Reply;
use App\Core\Router;
use App\Core\V;

final class MessagingRoutes
{
    public static function register(): void
    {
        Router::mount('/api/v1/messaging', [
            ['method' => 'POST', 'path' => '/send', 'perm' => 'activities:create', 'idempotent' => true, 'status' => 202, 'body' => V::obj([
                'leadId' => V::uuid(), 'channel' => V::enum(['email', 'whatsapp']), 'subject' => V::str()->trim()->max(200)->optional(),
                'body' => V::str()->max(4000)->default(''),
                'template' => V::obj(['name' => V::str()->trim()->min(1)->max(100), 'language' => V::str()->trim()->min(2)->max(10), 'params' => V::arr(V::str()->max(300))->max(10)->optional()])->optional(),
            ]), 'handler' => static fn(Ctx $c) => new Reply(MessagingService::send($c->actor, $c->body['leadId'], $c->body), 202)],
            ['method' => 'GET', 'path' => '/whatsapp', 'perm' => 'integrations:read', 'handler' => static fn(Ctx $c) => MessagingService::whatsapp($c->actor)],
            ['method' => 'PUT', 'path' => '/whatsapp', 'perm' => 'integrations:manage', 'body' => V::obj([
                'phoneNumberId' => V::str()->trim()->min(5)->max(40)->regex('/^\d+$/', 'The phone number id is digits only'),
                'accessToken' => V::str()->trim()->min(20)->max(1000),
            ]), 'handler' => static fn(Ctx $c) => MessagingService::connectWhatsapp($c->actor, $c->body)],
            ['method' => 'DELETE', 'path' => '/whatsapp', 'perm' => 'integrations:delete', 'status' => 204,
                'handler' => static function (Ctx $c) { MessagingService::disconnectWhatsapp($c->actor); return new Reply(null, 204); }],
        ]);
    }
}
