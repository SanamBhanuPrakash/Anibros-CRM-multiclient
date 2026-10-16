<?php
declare(strict_types=1);

namespace App\Core;

/**
 * PERMISSION MATRIX: single source of truth for authorization (a literal port of the Node matrix).
 * Scope 'all' = every record in the workspace, 'own' = only records the actor owns / is assigned to, absent = denied.
 */
final class Matrix
{
    public const ROLES = ['tenant_owner', 'tenant_admin', 'sales_manager', 'sales_agent', 'marketing_manager', 'marketing_analyst', 'executive_readonly', 'auditor'];

    private const OWNER = 'tenant_owner', ADMIN = 'tenant_admin', SM = 'sales_manager', SA = 'sales_agent', MM = 'marketing_manager', MA = 'marketing_analyst', EXEC = 'executive_readonly', AUD = 'auditor';

    /** @var array<string,array<string,string>>|null */
    private static ?array $m = null;

    /** @return array<string,array<string,string>> permission => role => scope */
    public static function all(): array
    {
        if (self::$m !== null) return self::$m;
        $g = static function (string $scope, string ...$roles): array {
            $o = [];
            foreach ($roles as $r) $o[$r] = $scope;
            return $o;
        };
        $mg = static fn(array ...$p): array => array_merge(...$p);
        $O = self::OWNER; $A = self::ADMIN; $SM = self::SM; $SA = self::SA; $MM = self::MM; $MA = self::MA; $E = self::EXEC; $AU = self::AUD;
        $admins = [$O, $A];
        $allButAuditor = [$O, $A, $SM, $SA, $MM, $MA, $E];
        return self::$m = [
            // Leads
            'leads:read' => $mg($g('all', $O, $A, $SM, $MM, $MA, $E), $g('own', $SA)),
            'leads:create' => $g('all', $O, $A, $SM, $SA),
            'leads:update' => $mg($g('all', $O, $A, $SM), $g('own', $SA)),
            'leads:delete' => $g('all', ...$admins),
            'leads:assign' => $g('all', $O, $A, $SM),
            'leads:claim' => $g('all', $O, $A, $SM, $SA),
            'leads:import' => $g('all', $O, $A, $SM, $MM),
            'leads:export' => $g('all', $O, $A, $SM, $MM, $MA),
            'leads:bulk' => $g('all', $O, $A, $SM),
            // Contacts
            'contacts:read' => $g('all', ...$allButAuditor),
            'contacts:create' => $g('all', $O, $A, $SM, $SA),
            'contacts:update' => $mg($g('all', $O, $A, $SM), $g('own', $SA)),
            'contacts:delete' => $g('all', ...$admins),
            'contacts:merge' => $g('all', $O, $A, $SM),
            'contacts:export' => $g('all', $O, $A, $SM, $MM, $MA),
            // Activities
            'activities:read' => $mg($g('all', $O, $A, $SM, $MM, $MA, $E), $g('own', $SA)),
            'activities:create' => $mg($g('all', $O, $A, $SM), $g('own', $SA)),
            // Inventory
            'properties:read' => $g('all', ...$allButAuditor),
            'properties:create' => $g('all', $O, $A, $SM),
            'properties:update' => $g('all', $O, $A, $SM),
            'properties:delete' => $g('all', ...$admins),
            'projects:read' => $g('all', ...$allButAuditor),
            'projects:create' => $g('all', $O, $A, $SM),
            'projects:update' => $g('all', $O, $A, $SM),
            'projects:delete' => $g('all', ...$admins),
            'matching:use' => $g('all', $O, $A, $SM, $SA, $MM),
            // Scheduling
            'viewings:read' => $mg($g('all', $O, $A, $SM, $MM, $MA, $E), $g('own', $SA)),
            'viewings:create' => $g('all', $O, $A, $SM, $SA),
            'viewings:update' => $mg($g('all', $O, $A, $SM), $g('own', $SA)),
            // Deals
            'reservations:read' => $mg($g('all', $O, $A, $SM, $MM, $MA, $E), $g('own', $SA)),
            'reservations:create' => $mg($g('all', $O, $A, $SM), $g('own', $SA)),
            'reservations:update' => $mg($g('all', $O, $A, $SM), $g('own', $SA)),
            'tasks:read' => $mg($g('all', $O, $A, $SM, $MM), $g('own', $SA)),
            'tasks:create' => $g('all', $O, $A, $SM, $SA, $MM),
            'tasks:update' => $mg($g('all', $O, $A, $SM), $g('own', $SA, $MM)),
            'tasks:delete' => $g('all', $O, $A, $SM),
            // Pipeline configuration
            'pipeline:read' => $g('all', ...$allButAuditor),
            'pipeline:manage' => $g('all', ...$admins),
            // Analytics
            'dashboard:read' => $mg($g('all', $O, $A, $SM, $MM, $MA, $E), $g('own', $SA)),
            'reports:read' => $g('all', $O, $A, $SM, $MM, $MA, $E),
            'reports:export' => $g('all', $O, $A, $SM, $MM, $MA),
            'attribution:read' => $g('all', $O, $A, $SM, $MM, $MA, $E),
            'attribution:manage' => $g('all', $O, $A, $MM),
            // Marketing / integrations / automation
            'integrations:read' => $g('all', $O, $A, $SM, $MM, $MA, $E),
            'integrations:manage' => $g('all', $O, $A, $MM),
            'integrations:delete' => $g('all', ...$admins),
            'automation:read' => $g('all', $O, $A, $SM, $MM, $MA),
            'automation:manage' => $g('all', $O, $A, $MM),
            'automation:delete' => $g('all', ...$admins),
            'custom_fields:read' => $g('all', ...$allButAuditor),
            'custom_fields:manage' => $g('all', ...$admins),
            'templates:read' => $g('all', $O, $A, $SM, $SA, $MM),
            'templates:manage' => $g('all', $O, $A, $MM),
            'messages:send' => $mg($g('all', $O, $A, $SM), $g('own', $SA)),
            // Workspace administration
            'members:directory' => $g('all', ...$allButAuditor),
            'members:read' => $g('all', $O, $A, $AU),
            'members:invite' => $g('all', ...$admins),
            'members:update' => $g('all', ...$admins),
            'members:remove' => $g('all', ...$admins),
            'tenant:read' => $g('all', ...$allButAuditor, ...[$AU]),
            'tenant:update' => $g('all', ...$admins),
            'branches:manage' => $g('all', ...$admins),
            'api_keys:manage' => $g('all', ...$admins),
            'webhooks:manage' => $g('all', ...$admins),
            'saved_views:manage' => $g('all', ...$allButAuditor),
            'tags:manage' => $g('all', $O, $A, $SM, $SA, $MM),
            // Oversight
            'audit:read' => $g('all', $O, $A, $AU),
            'operations:read' => $g('all', $O, $A, $AU, $MM),
            'operations:manage' => $g('all', $O, $A, $MM),
            'privacy:manage' => $g('all', ...$admins),
            // Everyone
            'notifications:read' => $g('all', ...$allButAuditor, ...[$AU]),
            'search:use' => $g('all', ...$allButAuditor),
            'ai:use' => $g('all', $O, $A, $SM, $SA, $MM),
        ];
    }

    /** @return string[] */
    public static function permissions(): array
    {
        return array_keys(self::all());
    }

    public static function isRole(mixed $v): bool
    {
        return is_string($v) && in_array($v, self::ROLES, true);
    }

    /** Scope granted to $role for $permission; null = denied. Unknown roles/permissions are denied (fail closed). */
    public static function scopeOf(?string $role, string $permission): ?string
    {
        if ($role === null || !self::isRole($role)) return null;
        return self::all()[$permission][$role] ?? null;
    }

    public static function can(?string $role, string $permission): bool
    {
        return self::scopeOf($role, $permission) !== null;
    }

    /** @return array<string,string> permission => scope, granted only */
    public static function capabilitiesFor(string $role): array
    {
        $out = [];
        foreach (self::permissions() as $p) {
            $s = self::scopeOf($role, $p);
            if ($s !== null) $out[$p] = $s;
        }
        return $out;
    }

    /** Roles a given role may assign to others (privilege-escalation guard). */
    public static function assignableRoles(string $actorRole): array
    {
        if ($actorRole === 'tenant_owner') return self::ROLES;
        if ($actorRole === 'tenant_admin') return array_values(array_filter(self::ROLES, static fn($r) => $r !== 'tenant_owner'));
        return [];
    }
}

/**
 * The authenticated principal performing an operation. Created by the authenticator, passed explicitly into services (never read
 * from globals), and doubles as the DB workspace context.
 */
final class Actor
{
    /** @param array<string,bool>|null $allowedPermissions API keys carry an explicit allow-list narrower than the role */
    public function __construct(
        public string $tenantId,
        public ?string $userId,
        public string $role,
        public string $kind = 'user', // user | api_key | system
        public ?string $apiKeyId = null,
        public ?array $allowedPermissions = null,
        public ?string $requestId = null,
        public ?string $ip = null,
    ) {
    }

    public static function system(string $tenantId, ?string $userId = null, string $role = 'tenant_owner', ?string $requestId = null, ?string $ip = null): self
    {
        return new self($tenantId, $userId, $role, 'system', null, null, $requestId, $ip);
    }

    public function permitted(string $permission): bool
    {
        if ($this->kind === 'system') return true;
        if ($this->allowedPermissions !== null && !isset($this->allowedPermissions[$permission])) return false;
        return Matrix::can($this->role, $permission);
    }

    /** Throws 403 unless the actor holds $permission (any scope). */
    public function assertCan(string $permission): void
    {
        if (!$this->permitted($permission)) throw Errors::forbidden();
    }

    /** Effective scope for list/read filters ('all' | 'own'). Throws 403 when denied. */
    public function scopeFor(string $permission): string
    {
        if ($this->kind === 'system') return 'all';
        $this->assertCan($permission);
        return Matrix::scopeOf($this->role, $permission);
    }

    /** Effective scope, or null when the actor lacks the permission (never throws): for aggregates that must degrade, not 403. */
    public function scopeIfAllowed(string $permission): ?string
    {
        if ($this->kind === 'system') return 'all';
        return $this->permitted($permission) ? Matrix::scopeOf($this->role, $permission) : null;
    }

    /** True when the actor may act on a record owned by $ownerId for this permission. */
    public function canActOn(string $permission, ?string $ownerId): bool
    {
        if (!$this->permitted($permission)) return false;
        $scope = $this->kind === 'system' ? 'all' : Matrix::scopeOf($this->role, $permission);
        if ($scope === 'all') return true;
        return $ownerId !== null && $ownerId === $this->userId;
    }

    public function assertCanActOn(string $permission, ?string $ownerId): void
    {
        // 404 (not 403) for out-of-scope records so existence is not revealed to an agent probing other agents' leads.
        if (!$this->permitted($permission)) throw Errors::forbidden();
        if (!$this->canActOn($permission, $ownerId)) throw Errors::notFound();
    }
}
