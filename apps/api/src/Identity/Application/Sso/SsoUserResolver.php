<?php

declare(strict_types=1);

namespace App\Identity\Application\Sso;

use App\Identity\Domain\Entity\Role;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Tenant;
use Symfony\Component\Uid\Uuid;

use const PASSWORD_ARGON2ID;

/**
 * RBAC-P2 SSO substrate (#661/#662/#663 base) — just-in-time provisioning
 * of local Users from external SSO identity claims.
 *
 * Per PRD-PIM-rbac §3.6:
 *   - find local User by email + tenant_id; return if found
 *   - otherwise auto-provision User with default 'viewer' role
 *   - never throw on missing user (the SSO claim IS the auth factor)
 *
 * Security:
 *   - email comes from a verified SSO assertion (caller MUST verify
 *     OAuth state / SAML signature before invoking this)
 *   - hosted_domain (Google) / tenant_id (Microsoft) restrictions enforced
 *     at the provider class level — this resolver trusts its input
 *
 * Role assignment policy: new SSO users get the 'viewer' role (read-only).
 * Tenant admin manually elevates via Phase 5 #693 (edit user — role
 * assignment + scope). Future Phase 5 backlog: SSO group claim → role
 * mapping (deferred per PRD §3.6 "future").
 */
final class SsoUserResolver
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly RoleRepositoryInterface $roles,
    ) {
    }

    /**
     * Resolve or auto-provision a User for the given SSO assertion.
     *
     * @return User the resolved / newly-created principal
     */
    public function resolveOrProvision(Tenant $tenant, string $email): User
    {
        $existing = $this->users->findByEmail($email);
        if (null !== $existing && $existing->getTenant()->getId()->equals($tenant->getId())) {
            return $existing;
        }

        // Auto-provision — SSO is the identity factor, no password needed.
        // Random placeholder hash so the User row is not "passwordless"
        // (PasswordAuthenticatedUserInterface contract). The user can
        // never log in via password until they trigger password-reset
        // flow (#658) themselves.
        $randomPlaceholder = bin2hex(random_bytes(32));
        $user = new User(
            tenant: $tenant,
            email: $email,
            passwordHash: password_hash($randomPlaceholder, PASSWORD_ARGON2ID),
            roles: ['ROLE_USER'],
            id: Uuid::v7(),
        );
        $this->users->save($user);

        // Assign default 'viewer' role for new SSO users. Tenant admin
        // elevates via Phase 5 settings UI.
        $viewerRole = $this->roles->findByCode('viewer', $tenant);
        if (null !== $viewerRole) {
            // ADR-0034 — one write path; cascade persist stores the assignment.
            $user->addRole($viewerRole);
            $this->users->save($user);
        }

        return $user;
    }
}
