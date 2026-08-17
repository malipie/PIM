<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Identity\Application\PermissionResolverInterface;
use App\Identity\Application\Policy\AttributePermissionPolicy;
use App\Identity\Application\Policy\ContractsUserScopedPermissionChecker;
use App\Identity\Application\Policy\LocaleChannelScopePolicy;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Rbac\PermissionSet;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P1-02 (#1954, SEC) — the by-user-id RBAC seam fails closed for
 * unknown users and delegates to the SAME policies manual actions use
 * (per-attribute 3-state + per-locale/channel scope).
 */
final class ContractsUserScopedPermissionCheckerTest extends TestCase
{
    #[Test]
    public function unknownUserFailsClosedOnEveryCheck(): void
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturn(null);

        $checker = new ContractsUserScopedPermissionChecker(
            $users,
            $this->createStub(AttributePermissionPolicy::class),
            // Final class - build it on a resolver stub that would allow
            // everything; the unknown-user guard must still deny first.
            new LocaleChannelScopePolicy($this->resolver(new PermissionSet([], [], []))),
        );

        $userId = Uuid::v7();
        self::assertFalse($checker->canViewAttribute($userId, Uuid::v7()));
        self::assertFalse($checker->canEditAttribute($userId, Uuid::v7()));
        self::assertFalse($checker->canEditLocale($userId, 'pl'));
        self::assertFalse($checker->canEditChannel($userId, 'web'));
    }

    #[Test]
    public function knownUserDelegatesToTheSamePoliciesAsManualActions(): void
    {
        $user = new User(new Tenant('alpha', 'Alpha'), 'kasia@example.com', 'hash');

        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturn($user);

        $attributeId = Uuid::v7();

        $attributePolicy = $this->createMock(AttributePermissionPolicy::class);
        $attributePolicy->expects(self::once())->method('canViewAttribute')->with($user, $attributeId)->willReturn(true);
        $attributePolicy->expects(self::once())->method('canEditAttribute')->with($user, $attributeId)->willReturn(false);

        // Final class - exercise it for real on a resolved scope of
        // locale=pl only and channel scope without 'web'.
        $scopePolicy = new LocaleChannelScopePolicy($this->resolver(new PermissionSet([], ['pl'], ['retail'])));

        $checker = new ContractsUserScopedPermissionChecker($users, $attributePolicy, $scopePolicy);

        $userId = $user->getId();
        self::assertTrue($checker->canViewAttribute($userId, $attributeId));
        self::assertFalse($checker->canEditAttribute($userId, $attributeId));
        self::assertTrue($checker->canEditLocale($userId, 'pl'));
        self::assertFalse($checker->canEditChannel($userId, 'web'));
    }

    private function resolver(PermissionSet $set): PermissionResolverInterface
    {
        return new class($set) implements PermissionResolverInterface {
            public function __construct(private readonly PermissionSet $set)
            {
            }

            public function resolve(User $user): PermissionSet
            {
                return $this->set;
            }

            public function invalidateRole(string $roleId): void
            {
                // This double answers permission questions; cache
                // invalidation is not part of what it is standing in for.
            }
        };
    }
}
