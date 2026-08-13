<?php

declare(strict_types=1);

namespace App\Identity\Domain\Entity;

use App\Identity\Contracts\Event\UserAuthenticated;
use App\Shared\Application\TenantAware;
use App\Shared\Application\UserIdentityAware;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use LogicException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Application user with a tenant scope.
 *
 * Sprint 0 minimal shape — email + password hash + roles. Full RBAC and 2FA
 * land in epics 0.2 (#24+) and 0.11; for the gate-decision slice this is
 * enough to authenticate via JWT and resolve the active tenant from the
 * authenticated principal (replacing the APP_DEFAULT_TENANT_CODE fallback
 * once auth covers the request).
 *
 * The TenantAware interface lets CurrentTenantProvider read the tenant from
 * the security token's user without coupling Identity to Catalog.
 */
class User extends AggregateRoot implements UserInterface, PasswordAuthenticatedUserInterface, TenantAware, UserIdentityAware
{
    public const string STATUS_ACTIVE = 'active';
    public const string STATUS_DISABLED = 'disabled';

    private Uuid $id;

    private Tenant $tenant;

    private string $email;

    private string $password;

    /**
     * Legacy Sprint-0 channel for Symfony Security roles, merged into
     * getRoles() alongside the assignments. ADR-0034 schedules this column
     * for removal in the contract step, once the consolidated assignments
     * are confirmed in production.
     *
     * @var list<string>
     */
    private array $roles;

    /**
     * ADR-0034 (#2832) — role assignments carrying their scope. Replaces the
     * Sprint-0 `user_roles` M2M, which had no scope columns and therefore
     * widened locale/channel-restricted grants (AUD-029).
     *
     * @var Collection<int, UserRole>
     */
    private Collection $roleAssignments;

    private string $status;

    private ?DateTimeImmutable $lastLoginAt;

    /**
     * RFC 6238 TOTP shared secret in base32 encoding. Null while the
     * user has not started 2FA enrolment, populated by
     * {@see \App\Identity\Application\TotpEnrolmentService::enrol},
     * confirmed by `confirmTotpEnrolment()`.
     *
     * Encrypted at rest since #2726: the column holds a
     * {@see \App\Shared\Application\Crypto\SecretCipher} envelope
     * (`enc:v{N}:{base64}`), produced with the AES-256-GCM master key
     * (ADR-0017). Reads go through
     * {@see \App\Identity\Application\TotpEnrolmentService}, which reveals
     * it; rows written before #2726 stay plaintext and migrate on their next
     * write, so this getter may return either form — never treat it as the
     * base32 secret directly.
     */
    private ?string $totpSecret;

    private ?DateTimeImmutable $totpEnabledAt;

    /**
     * One-shot recovery codes — Argon2id hashes, single-use. Replenished
     * via the dedicated rotate endpoint; consumed by the login flow's
     * fallback path for users locked out of the authenticator app.
     *
     * @var list<string>
     */
    private array $totpBackupCodes;

    private DateTimeImmutable $createdAt;

    /**
     * Manual user creation (#867). TRUE means the admin set the password
     * via `POST /api/users` and the user must replace it on first login —
     * AuthedRoute blocks navigation away from `/first-login-password`
     * until the flag is cleared by a successful change-password call.
     */
    private bool $passwordChangeRequired;

    /**
     * @param list<string> $roles
     */
    public function __construct(
        Tenant $tenant,
        string $email,
        string $passwordHash,
        array $roles = ['ROLE_USER'],
        ?Uuid $id = null,
        bool $passwordChangeRequired = false,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->tenant = $tenant;
        $this->email = $email;
        $this->password = $passwordHash;
        $this->roles = $roles;
        $this->roleAssignments = new ArrayCollection();
        $this->status = self::STATUS_ACTIVE;
        $this->lastLoginAt = null;
        $this->totpSecret = null;
        $this->totpEnabledAt = null;
        $this->totpBackupCodes = [];
        $this->createdAt = new DateTimeImmutable();
        $this->passwordChangeRequired = $passwordChangeRequired;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * Swap the stored password hash. The controller layer is responsible
     * for hashing the plaintext before calling this (so the entity stays
     * agnostic of the hashing strategy) and for verifying the previous
     * password — this method is intentionally low-level and does NOT
     * re-authenticate. See {@see \App\Identity\Presentation\Controller\ChangePasswordController}.
     */
    public function changePassword(string $newPasswordHash): void
    {
        $this->password = $newPasswordHash;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;

        // ADR-0034 (#2832) — role strings come from the assignment table,
        // the single record of who holds what. Note these are NOT an
        // authorization path: nothing in the app calls
        // `isGranted('ROLE_*')`; permissions are decided by codes through
        // the voters. They exist because Symfony Security expects a user to
        // report roles, and they keep the JWT readable during debugging.
        foreach ($this->roleAssignments as $assignment) {
            $roles[] = 'ROLE_'.strtoupper($assignment->getRole()->getCode());
        }

        // Symfony convention — every authenticated user must have ROLE_USER
        // even when not stored explicitly, so access_control rules behave as
        // documented across the framework.
        if (!\in_array('ROLE_USER', $roles, true)) {
            $roles[] = 'ROLE_USER';
        }

        return array_values(array_unique($roles));
    }

    public function getUserIdentifier(): string
    {
        \assert('' !== $this->email, 'User email is enforced NOT NULL by the schema and never empty.');

        return $this->email;
    }

    public function eraseCredentials(): void
    {
        // No transient sensitive data — password hash stays for re-auth flows.
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return self::STATUS_ACTIVE === $this->status;
    }

    public function disable(): void
    {
        $this->status = self::STATUS_DISABLED;
    }

    public function enable(): void
    {
        $this->status = self::STATUS_ACTIVE;
    }

    public function getLastLoginAt(): ?DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function recordLogin(?DateTimeImmutable $when = null): void
    {
        $this->lastLoginAt = $when ?? new DateTimeImmutable();
        $this->recordThat(new UserAuthenticated(
            userId: $this->id,
            tenantId: $this->tenant->getId(),
            email: $this->email,
            occurredOn: $this->lastLoginAt,
        ));
    }

    /**
     * ADR-0034 (#2832) — the assignments themselves, scope included. Callers
     * that only need the roles use {@see getAssignedRoles()}.
     *
     * @return Collection<int, UserRole>
     */
    public function getRoleAssignments(): Collection
    {
        return $this->roleAssignments;
    }

    /**
     * @return Collection<int, Role>
     */
    public function getAssignedRoles(): Collection
    {
        return new ArrayCollection(
            $this->roleAssignments->map(static fn (UserRole $assignment): Role => $assignment->getRole())
                ->getValues(),
        );
    }

    /**
     * Grants the role with no scope restrictions.
     *
     * ADR-0034: this writes to `user_role_assignments` — the single record of
     * who holds what. Callers (tenant bootstrap, fixtures, break-glass, the
     * users panel, tests) are unchanged; only the destination is.
     */
    public function addRole(Role $role): void
    {
        if ($this->hasAssignedRole($role)) {
            return;
        }

        $this->roleAssignments->add(new UserRole($this, $role));
    }

    public function removeRole(Role $role): void
    {
        foreach ($this->roleAssignments as $assignment) {
            if ($assignment->getRole()->getId()->equals($role->getId())) {
                // orphanRemoval on the mapping turns this into a DELETE.
                $this->roleAssignments->removeElement($assignment);
            }
        }
    }

    private function hasAssignedRole(Role $role): bool
    {
        foreach ($this->roleAssignments as $assignment) {
            if ($assignment->getRole()->getId()->equals($role->getId())) {
                return true;
            }
        }

        return false;
    }

    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
    }

    public function isTotpEnabled(): bool
    {
        return null !== $this->totpEnabledAt;
    }

    public function getTotpEnabledAt(): ?DateTimeImmutable
    {
        return $this->totpEnabledAt;
    }

    /**
     * @return list<string>
     */
    public function getTotpBackupCodes(): array
    {
        return $this->totpBackupCodes;
    }

    /**
     * Stamps the user with a freshly generated TOTP secret + recovery
     * codes, but does NOT enable 2FA yet — `confirmTotpEnrolment()`
     * does that after the first successful code verification, so a
     * dropped enrolment never locks the user out.
     *
     * @param list<string> $backupCodeHashes
     */
    public function startTotpEnrolment(string $secret, array $backupCodeHashes): void
    {
        $this->totpSecret = $secret;
        $this->totpBackupCodes = $backupCodeHashes;
        $this->totpEnabledAt = null;
    }

    public function confirmTotpEnrolment(?DateTimeImmutable $when = null): void
    {
        if (null === $this->totpSecret) {
            throw new LogicException('Cannot confirm TOTP enrolment before a secret has been provisioned.');
        }
        $this->totpEnabledAt = $when ?? new DateTimeImmutable();
    }

    public function disableTotp(): void
    {
        $this->totpSecret = null;
        $this->totpEnabledAt = null;
        $this->totpBackupCodes = [];
    }

    /**
     * Marks one backup code as consumed. The caller is responsible for
     * matching the cleartext code against one of the stored hashes;
     * this method just removes the matching hash from the active list.
     */
    public function consumeBackupCode(string $usedHash): void
    {
        $this->totpBackupCodes = array_values(array_filter(
            $this->totpBackupCodes,
            static fn (string $hash): bool => $hash !== $usedHash,
        ));
    }

    public function isPasswordChangeRequired(): bool
    {
        return $this->passwordChangeRequired;
    }

    public function markPasswordChangeRequired(): void
    {
        $this->passwordChangeRequired = true;
    }

    public function clearPasswordChangeRequired(): void
    {
        $this->passwordChangeRequired = false;
    }
}
