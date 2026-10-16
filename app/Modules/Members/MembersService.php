<?php
declare(strict_types=1);

namespace App\Modules\Members;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Db;
use App\Core\Errors;
use App\Core\Events;
use App\Core\Ids;
use App\Core\Mailer;
use App\Core\Matrix;
use App\Core\Password;
use App\Core\Raw;
use App\Core\Time;
use App\Core\Tx;
use App\Modules\Identity\Sessions;

final class MembersService
{
    private const INVITE_TTL = '+7 days';

    /** id + display name only: enough for assignee pickers, nothing sensitive. */
    public static function directory(Actor $actor): array
    {
        return Db::tenant($actor)->read(static fn(Tx $tx) => $tx->all(
            "SELECT u.id AS userId, u.name, tm.role FROM tenant_memberships tm JOIN users u ON u.id = tm.user_id
              WHERE tm.tenant_id = ? AND tm.status = 'active' AND tm.is_active = 1 AND u.is_active = 1 ORDER BY u.name, u.id",
            [$actor->tenantId]
        ));
    }

    /** @return array{members:array,invitations:array} */
    public static function list(Actor $actor): array
    {
        $out = Db::tenant($actor)->read(static function (Tx $tx) use ($actor) {
            $members = $tx->all(
                "SELECT tm.id AS membershipId, u.id AS userId, u.name, u.email, tm.role, tm.status, tm.created_at AS joinedAt
                   FROM tenant_memberships tm JOIN users u ON u.id = tm.user_id
                  WHERE tm.tenant_id = ? AND tm.status IN ('active', 'suspended') ORDER BY tm.created_at, tm.id",
                [$actor->tenantId]
            );
            $inv = $tx->all(
                'SELECT id, email, role, invited_by AS invitedBy, expires_at AS expiresAt, created_at AS createdAt FROM tenant_invitations
                  WHERE tenant_id = ? AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at > NOW(3) ORDER BY created_at DESC, id',
                [$actor->tenantId]
            );
            return ['members' => $members, 'invitations' => $inv];
        });
        // MFA on / last seen of the people of THIS workspace (identity tables: only the ids just read above are asked about)
        $sec = [];
        $ids = array_column($out['members'], 'userId');
        if ($ids) {
            foreach (Db::identity()->query(
                'SELECT u.id AS user_id, EXISTS (SELECT 1 FROM mfa_factors f WHERE f.user_id = u.id AND f.confirmed_at IS NOT NULL) AS mfa,
                        (SELECT MAX(s.last_seen_at) FROM sessions s WHERE s.user_id = u.id AND s.revoked_at IS NULL) AS last_seen
                   FROM users u WHERE u.id IN (?)',
                [$ids]
            ) as $r) $sec[$r['user_id']] = $r;
        }
        foreach ($out['members'] as &$m) {
            $m['mfaEnabled'] = (bool) ($sec[$m['userId']]['mfa'] ?? false);
            $m['lastSeenAt'] = $sec[$m['userId']]['last_seen'] ?? null;
        }
        unset($m);
        return $out;
    }

    private static function assertCanGrant(Actor $actor, string $role): void
    {
        if (!in_array($role, Matrix::assignableRoles($actor->role), true)) throw Errors::forbidden("Your role cannot grant \"$role\"");
    }

    /** @param array{email:string,role:string} $in */
    public static function invite(Actor $actor, array $in, string $actorName): array
    {
        self::assertCanGrant($actor, $in['role']);
        $email = strtolower(trim($in['email']));
        $token = Sessions::generateToken(32);
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $in, $email, $token, $actorName) {
            $existing = $tx->exists(
                "SELECT 1 FROM tenant_memberships tm JOIN users u ON u.id = tm.user_id WHERE tm.tenant_id = ? AND u.email = ? AND tm.status IN ('active', 'suspended')",
                [$actor->tenantId, $email]
            );
            if ($existing) throw Errors::conflict('This person is already a member of the workspace');
            // Re-inviting replaces any pending invitation to the same address (one live invitation per e-mail).
            $tx->run('UPDATE tenant_invitations SET revoked_at = NOW(3) WHERE tenant_id = ? AND email = ? AND accepted_at IS NULL AND revoked_at IS NULL', [$actor->tenantId, $email]);
            $id = $tx->insert('tenant_invitations', [
                'tenant_id' => $actor->tenantId, 'email' => $email, 'role' => $in['role'], 'invited_by' => $actor->userId,
                'token_hash' => Sessions::hashToken($token), 'expires_at' => Time::plus(self::INVITE_TTL),
            ]);
            $row = $tx->one('SELECT id, email, role, expires_at AS expiresAt FROM tenant_invitations WHERE id = ?', [$id]);
            $tenantName = $tx->val('SELECT name FROM tenants WHERE id = ?', [$actor->tenantId]);
            Mailer::enqueue($tx, 'invitation', $email, ['tenantName' => $tenantName, 'inviterName' => $actorName, 'role' => $in['role']], $token);
            Audit::log($tx, 'member.invite', 'invitation', $id, null, ['email' => $email, 'role' => $in['role']]);
            Events::emit($tx, 'member.invited', $id, ['role' => $in['role']]);
            return $row;
        });
    }

    public static function revokeInvitation(Actor $actor, string $id): void
    {
        Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id) {
            $r = $tx->one('SELECT email, role FROM tenant_invitations WHERE id = ? AND tenant_id = ? AND accepted_at IS NULL AND revoked_at IS NULL FOR UPDATE', [$id, $actor->tenantId]);
            if (!$r) throw Errors::notFound('Invitation');
            $tx->run('UPDATE tenant_invitations SET revoked_at = NOW(3) WHERE id = ? AND tenant_id = ?', [$id, $actor->tenantId]);
            Audit::log($tx, 'member.invitation_revoke', 'invitation', $id, $r);
        });
    }

    /** The workspace must always keep at least one active owner, even under concurrent requests (lock every owner row before counting). */
    private static function lockOwners(Tx $tx, string $tenantId): int
    {
        return count($tx->all("SELECT id FROM tenant_memberships WHERE tenant_id = ? AND role = 'tenant_owner' AND status = 'active' FOR UPDATE", [$tenantId]));
    }

    /** @param array{role?:string,status?:string} $patch */
    public static function update(Actor $actor, string $membershipId, array $patch): array
    {
        if (!empty($patch['role'])) self::assertCanGrant($actor, $patch['role']);
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $membershipId, $patch) {
            $owners = self::lockOwners($tx, $actor->tenantId);
            $m = $tx->one('SELECT id, user_id, role, status FROM tenant_memberships WHERE id = ? AND tenant_id = ? FOR UPDATE', [$membershipId, $actor->tenantId]);
            if (!$m || $m['status'] === 'revoked') throw Errors::notFound('Member');
            if ($m['role'] === 'tenant_owner' && $actor->role !== 'tenant_owner') throw Errors::forbidden('Only an owner can change an owner');
            $losingOwner = $m['role'] === 'tenant_owner' && ((!empty($patch['role']) && $patch['role'] !== 'tenant_owner') || ($patch['status'] ?? null) === 'suspended');
            if ($losingOwner && $owners <= 1) throw Errors::conflict('A workspace must keep at least one active owner');
            $tx->run(
                'UPDATE tenant_memberships SET role = COALESCE(?, role), status = COALESCE(?, status), updated_by = ? WHERE id = ? AND tenant_id = ?',
                [$patch['role'] ?? null, $patch['status'] ?? null, $actor->userId, $membershipId, $actor->tenantId]
            );
            $up = $tx->one('SELECT id AS membershipId, user_id AS userId, role, status FROM tenant_memberships WHERE id = ? AND tenant_id = ?', [$membershipId, $actor->tenantId]);
            Audit::log($tx, 'member.update', 'membership', $membershipId, ['role' => $m['role'], 'status' => $m['status']], ['role' => $up['role'], 'status' => $up['status']]);
            Events::emit($tx, 'member.updated', $membershipId, ['role' => $up['role'], 'status' => $up['status']]);
            return $up;
        });
    }

    public static function remove(Actor $actor, string $membershipId): void
    {
        $removedUser = Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $membershipId) {
            $owners = self::lockOwners($tx, $actor->tenantId);
            $m = $tx->one('SELECT id, user_id, role, status FROM tenant_memberships WHERE id = ? AND tenant_id = ? FOR UPDATE', [$membershipId, $actor->tenantId]);
            if (!$m || $m['status'] === 'revoked') throw Errors::notFound('Member');
            if ($m['role'] === 'tenant_owner' && $actor->role !== 'tenant_owner') throw Errors::forbidden('Only an owner can remove an owner');
            if ($m['role'] === 'tenant_owner' && $owners <= 1) throw Errors::conflict('A workspace must keep at least one active owner');
            $tx->run("UPDATE tenant_memberships SET status = 'revoked', is_active = 0, updated_by = ? WHERE id = ? AND tenant_id = ?", [$actor->userId, $membershipId, $actor->tenantId]);
            Audit::log($tx, 'member.remove', 'membership', $membershipId, ['role' => $m['role']]);
            Events::emit($tx, 'member.updated', $membershipId, ['status' => 'revoked']);
            return $m['user_id'];
        });
        // Their sessions that point at this workspace keep working elsewhere; the authenticator re-checks membership on every request.
        Sessions::detach($actor->tenantId, $removedUser);
    }

    // ── invitation acceptance (public; runs before any workspace context exists) ──────────────────────────────

    /** Reveals only what the link holder needs. */
    public static function preview(string $token): array
    {
        $i = Db::identity()->one(
            'SELECT i.id, i.tenant_id, t.name AS tenant_name, i.email, i.role, i.expires_at, i.accepted_at, i.revoked_at
               FROM tenant_invitations i JOIN tenants t ON t.id = i.tenant_id WHERE i.token_hash = ?',
            [Sessions::hashToken($token)]
        );
        if (!$i || $i['accepted_at'] || $i['revoked_at'] || strtotime($i['expires_at']) < time()) throw Errors::notFound('Invitation');
        $exists = Db::identity()->exists('SELECT 1 FROM users WHERE email = ?', [$i['email']]);
        return ['email' => $i['email'], 'tenantName' => $i['tenant_name'], 'role' => $i['role'], 'accountExists' => $exists];
    }

    /** Atomic, single-use: returns the invitation row or null (wrong mailbox, expired, used or revoked are indistinguishable on purpose). */
    private static function consume(Tx $tx, string $tokenHash, ?string $email): ?array
    {
        $n = $tx->run(
            'UPDATE tenant_invitations SET accepted_at = NOW(3) WHERE token_hash = ? AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at > NOW(3) AND (? IS NULL OR email = ?)',
            [$tokenHash, $email, $email]
        );
        if (!$n) return null;
        return $tx->one('SELECT id, tenant_id, email, role, invited_by FROM tenant_invitations WHERE token_hash = ?', [$tokenHash]);
    }

    /** Accept as an already-signed-in user whose e-mail matches the invitation. */
    public static function acceptAsExistingUser(string $token, array $user): array
    {
        return Db::identity()->tx(static function (Tx $tx) use ($token, $user) {
            $i = self::consume($tx, Sessions::hashToken($token), $user['email']);
            if (!$i) throw Errors::notFound('Invitation');
            self::addMembership($tx, $i['tenant_id'], $user['id'], $i['role'], $i['invited_by']);
            return ['tenantId' => $i['tenant_id']];
        });
    }

    /** Accept as a brand-new user: the invitation proves mailbox ownership, so the address is verified at once. */
    public static function acceptAsNewUser(string $token, array $in, array $meta): array
    {
        $pre = self::preview($token);
        if ($pre['accountExists']) throw Errors::conflict('An account with this email already exists. Sign in to accept the invitation.', ['code' => 'LOGIN_REQUIRED']);
        $problems = Password::problems($in['password'], $pre['email'], $in['name']);
        if ($problems) throw Errors::validation(array_map(static fn($m) => ['path' => 'password', 'message' => $m], $problems));
        $hash = Password::hash($in['password']);
        $userId = Db::identity()->tx(static function (Tx $tx) use ($token, $pre, $in, $hash) {
            $i = self::consume($tx, Sessions::hashToken($token), $pre['email']);
            if (!$i) throw Errors::notFound('Invitation');
            $uid = $tx->insertIgnore('users', ['email' => $pre['email'], 'password_hash' => $hash, 'name' => trim($in['name']), 'email_verified' => 1]);
            if (!$uid) throw Errors::conflict('An account with this email already exists. Sign in to accept the invitation.');
            self::addMembership($tx, $i['tenant_id'], $uid, $i['role'], $i['invited_by']);
            return $uid;
        });
        $created = Sessions::create($userId, $meta['ip'] ?? null, $meta['userAgent'] ?? null);
        $session = Sessions::validate($created['token']);
        return ['token' => $created['token'], 'expiresAt' => $created['expiresAt'], 'session' => $session];
    }

    private static function addMembership(Tx $tx, string $tenantId, string $userId, string $role, ?string $invitedBy): void
    {
        if (!Matrix::isRole($role)) throw Errors::internal();
        $tx->insert('tenant_memberships', ['tenant_id' => $tenantId, 'user_id' => $userId, 'role' => $role, 'status' => 'active', 'is_active' => 1, 'invited_by' => $invitedBy], [
            'onDuplicate' => ['role' => new Raw('VALUES(role)'), 'status' => 'active', 'is_active' => 1, 'invited_by' => new Raw('VALUES(invited_by)')],
        ]);
        // Audit + event inside the new member's workspace context
        $tx->actAs($tenantId, $userId);
        Audit::log($tx, 'member.join', 'membership', $userId, null, ['role' => $role]);
        Events::emit($tx, 'member.updated', $userId, ['role' => $role, 'status' => 'active']);
    }
}
