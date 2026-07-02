<?php

declare(strict_types=1);

namespace App\Identity\Application\Policy;

use App\Identity\Contracts\Policy\UserScopedPermissionCheckerInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P1-02 (#1954) — Identity-side adapter of the by-user-id RBAC
 * seam: loads the user and delegates to the same policies every manual
 * action goes through (AttributePermissionPolicy 3-state resolution,
 * LocaleChannelScopePolicy). Unknown user id -> false (fail closed).
 */
final readonly class ContractsUserScopedPermissionChecker implements UserScopedPermissionCheckerInterface
{
    public function __construct(
        private UserRepositoryInterface $users,
        private AttributePermissionPolicy $attributePolicy,
        private LocaleChannelScopePolicy $scopePolicy,
    ) {
    }

    public function canViewAttribute(Uuid $userId, Uuid $attributeId): bool
    {
        $user = $this->users->findById($userId);

        return null !== $user && $this->attributePolicy->canViewAttribute($user, $attributeId);
    }

    public function canEditAttribute(Uuid $userId, Uuid $attributeId): bool
    {
        $user = $this->users->findById($userId);

        return null !== $user && $this->attributePolicy->canEditAttribute($user, $attributeId);
    }

    public function canEditLocale(Uuid $userId, string $locale): bool
    {
        $user = $this->users->findById($userId);

        return null !== $user && $this->scopePolicy->canEditLocale($user, $locale);
    }

    public function canEditChannel(Uuid $userId, string $channel): bool
    {
        $user = $this->users->findById($userId);

        return null !== $user && $this->scopePolicy->canEditChannel($user, $channel);
    }
}
