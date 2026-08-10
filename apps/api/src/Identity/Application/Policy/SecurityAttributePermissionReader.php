<?php

declare(strict_types=1);

namespace App\Identity\Application\Policy;

use App\Identity\Contracts\Policy\AttributePermissionReader;
use App\Identity\Domain\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * ULV-04b (#986) — adapter wiring the {@see AttributePermissionReader}
 * contract to the existing {@see AttributePermissionPolicy} resolver +
 * Symfony security token.
 *
 * The resolver expects a {@see User} entity; this adapter pulls it from
 * the security token. Anonymous principals → false (restricted) because
 * no per-attribute grant can apply without a user identity.
 */
final readonly class SecurityAttributePermissionReader implements AttributePermissionReader
{
    public function __construct(
        private Security $security,
        private AttributePermissionPolicy $policy,
    ) {
    }

    public function canViewAttribute(Uuid $attributeId): bool
    {
        $user = $this->currentUser();
        if (null === $user) {
            return false;
        }

        return $this->policy->canViewAttribute($user, $attributeId);
    }

    public function canViewAttributes(array $attributeIds): array
    {
        $user = $this->currentUser();
        if (null === $user) {
            // Anonymous → restricted, same default as the single-id path.
            $denied = [];
            foreach ($attributeIds as $attributeId) {
                $denied[$attributeId->toRfc4122()] = false;
            }

            return $denied;
        }

        $decisions = [];
        foreach ($this->policy->resolvePermissions($user, $attributeIds) as $key => $permission) {
            $decisions[$key] = $permission->canView();
        }

        return $decisions;
    }

    public function canEditAttribute(Uuid $attributeId): bool
    {
        $user = $this->currentUser();
        if (null === $user) {
            return false;
        }

        return $this->policy->canEditAttribute($user, $attributeId);
    }

    public function isAttributePermissionEnforced(): bool
    {
        return null !== $this->currentUser();
    }

    private function currentUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
