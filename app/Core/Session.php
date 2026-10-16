<?php
declare(strict_types=1);

namespace App\Core;

/**
 * A validated session (user, their workspaces, MFA flag). Produced by Modules\Identity\Sessions::validate().
 * Lives in Core so the HTTP layer can type against it without depending on a module.
 */
final class Session
{
    /**
     * @param array{id:string,email:string,name:string,phone:?string,avatarUrl:?string,emailVerified:bool,timezone:string,locale:string,mfaEnabled:bool} $user
     * @param array<int,array{id:string,name:string,slug:string,role:string,timezone:string,currency:string,kind:string,industry:?string,workChannels:?array}> $tenants
     */
    public function __construct(
        public string $sessionId,
        public int $authLevel,
        public ?string $activeTenantId,
        public ?string $createdAt,
        public array $user,
        public array $tenants,
    ) {
    }
}
