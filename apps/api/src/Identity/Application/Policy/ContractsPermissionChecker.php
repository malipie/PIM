<?php

declare(strict_types=1);

namespace App\Identity\Application\Policy;

use App\Identity\Application\PermissionResolverInterface;
use App\Identity\Contracts\Policy\PermissionCheckerInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P0-07 (#1950) — Identity-side adapter of the Contracts
 * permission-check seam: loads the user and asks the RBAC
 * PermissionResolver whether the resolved set carries the code.
 *
 * Unknown user id -> false (fail closed).
 */
final readonly class ContractsPermissionChecker implements PermissionCheckerInterface
{
    public function __construct(
        private UserRepositoryInterface $users,
        private PermissionResolverInterface $permissions,
    ) {
    }

    public function userHasPermission(Uuid $userId, string $permissionCode): bool
    {
        $user = $this->users->findById($userId);
        if (null === $user) {
            return false;
        }

        return $this->permissions->resolve($user)->has($permissionCode);
    }
}
