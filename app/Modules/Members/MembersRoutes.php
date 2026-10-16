<?php
declare(strict_types=1);

namespace App\Modules\Members;

use App\Core\Csrf;
use App\Core\Ctx;
use App\Core\Errors;
use App\Core\Matrix;
use App\Core\Reply;
use App\Core\Router;
use App\Core\V;
use App\Modules\Identity\Authenticator;
use App\Modules\Identity\Sessions;

final class MembersRoutes
{
    public static function register(): void
    {
        $role = V::enum(Matrix::ROLES);
        $id = V::obj(['id' => V::uuid()]);
        Router::mount('/api/v1/members', [
            ['method' => 'GET', 'path' => '/directory', 'perm' => 'members:directory', 'handler' => fn(Ctx $c) => MembersService::directory($c->actor)],
            ['method' => 'GET', 'path' => '/', 'perm' => 'members:read', 'handler' => fn(Ctx $c) => MembersService::list($c->actor)],
            ['method' => 'POST', 'path' => '/invitations', 'perm' => 'members:invite', 'idempotent' => true,
                'body' => V::obj(['email' => V::str()->trim()->lower()->email()->max(254), 'role' => $role]),
                'handler' => fn(Ctx $c) => new Reply(MembersService::invite($c->actor, $c->body, $c->session?->user['name'] ?? 'A teammate'), 201)],
            ['method' => 'DELETE', 'path' => '/invitations/{id}', 'perm' => 'members:invite', 'params' => $id, 'status' => 204,
                'handler' => function (Ctx $c) { MembersService::revokeInvitation($c->actor, $c->params['id']); return new Reply(null, 204); }],
            ['method' => 'PATCH', 'path' => '/{id}', 'perm' => 'members:update', 'params' => $id,
                'body' => V::obj(['role' => (clone $role)->optional(), 'status' => V::enum(['active', 'suspended'])->optional()])->refine(static fn($b) => isset($b['role']) || isset($b['status']), 'Provide role or status'),
                'handler' => fn(Ctx $c) => MembersService::update($c->actor, $c->params['id'], $c->body)],
            ['method' => 'DELETE', 'path' => '/{id}', 'perm' => 'members:remove', 'params' => $id, 'status' => 204,
                'handler' => function (Ctx $c) { MembersService::remove($c->actor, $c->params['id']); return new Reply(null, 204); }],
        ]);

        // Public acceptance endpoints (the link holder is not signed in / has no workspace yet).
        Router::mount('/api/v1/invitations', [
            ['method' => 'POST', 'path' => '/preview', 'auth' => 'public', 'status' => 200,
                'body' => V::obj(['token' => V::str()->min(20)->max(200)]),
                'handler' => fn(Ctx $c) => new Reply(MembersService::preview($c->body['token']), 200)],
            ['method' => 'POST', 'path' => '/accept', 'auth' => 'public', 'status' => 200,
                'body' => V::obj(['token' => V::str()->min(20)->max(200), 'name' => V::str()->trim()->min(2)->max(100)->optional(), 'password' => V::str()->min(1)->max(128)->optional()]),
                'handler' => function (Ctx $c) {
                    $b = $c->body;
                    $meta = ['ip' => $c->req->ip, 'userAgent' => $c->req->userAgent()];
                    $current = Authenticator::currentSession($c->req);
                    if ($current) {
                        // Someone else may be signed in in this browser (typical when testing an invite as the owner): say so instead of the
                        // generic "not found" the mailbox check would produce. preview() still answers 404 for invalid tokens.
                        $pre = MembersService::preview($b['token']);
                        if (strtolower($pre['email']) !== strtolower($current->user['email'])) {
                            throw Errors::conflict("You are signed in as {$current->user['email']}, but this invitation is for {$pre['email']}. Sign out, then open the invitation link again.", ['code' => 'WRONG_ACCOUNT']);
                        }
                        $r = MembersService::acceptAsExistingUser($b['token'], ['id' => $current->user['id'], 'email' => $current->user['email']]);
                        return new Reply(['status' => 'joined', 'tenantId' => $r['tenantId']], 200);
                    }
                    if (empty($b['name']) || empty($b['password'])) throw Errors::badRequest('Provide name and password to create your account, or sign in first if you already have one');
                    $r = MembersService::acceptAsNewUser($b['token'], ['name' => $b['name'], 'password' => $b['password']], $meta);
                    Sessions::setCookie($c->req, $c->res, $r['token'], $r['expiresAt']);
                    return new Reply(['status' => 'account_created', 'csrfToken' => Csrf::issue($c->req, $c->res), 'activeTenantId' => $r['session']->activeTenantId], 200);
                }],
        ]);
    }
}
