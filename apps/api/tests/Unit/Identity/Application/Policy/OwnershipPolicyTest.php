<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application\Policy;

use App\Identity\Application\PermissionResolverInterface;
use App\Identity\Application\Policy\OwnershipPolicy;
use App\Identity\Application\Policy\OwnershipScope;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Rbac\PermissionSet;
use App\Shared\Domain\Tenant;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * RBAC-P3-010 (#673) — OwnershipPolicy unit coverage of the
 * own / all routing per resource type.
 */
final class OwnershipPolicyTest extends TestCase
{
    #[Test]
    public function canViewOwnReadsCorrectCodePerResource(): void
    {
        $policy = new OwnershipPolicy($this->resolverWith(['exports.view_own']));

        self::assertTrue($policy->canViewOwn($this->user(), 'exports'));
        self::assertFalse($policy->canViewOwn($this->user(), 'imports'));
    }

    #[Test]
    public function canViewAllReadsCorrectCodePerResource(): void
    {
        $policy = new OwnershipPolicy($this->resolverWith(['exports.view_all']));

        self::assertTrue($policy->canViewAll($this->user(), 'exports'));
        self::assertFalse($policy->canViewAll($this->user(), 'imports'));
    }

    #[Test]
    public function effectiveScopeReturnsNullWhenNeitherCodeGranted(): void
    {
        $policy = new OwnershipPolicy($this->resolverWith([]));

        self::assertNull($policy->effectiveScope($this->user(), 'exports', OwnershipScope::Own));
        self::assertNull($policy->effectiveScope($this->user(), 'exports', OwnershipScope::All));
        self::assertNull($policy->effectiveScope($this->user(), 'exports', null));
    }

    #[Test]
    public function effectiveScopeWithOnlyOwnDeniesAllScopeRequest(): void
    {
        $policy = new OwnershipPolicy($this->resolverWith(['exports.view_own']));

        self::assertSame(OwnershipScope::Own, $policy->effectiveScope($this->user(), 'exports', null));
        self::assertSame(OwnershipScope::Own, $policy->effectiveScope($this->user(), 'exports', OwnershipScope::Own));
        self::assertNull($policy->effectiveScope($this->user(), 'exports', OwnershipScope::All));
    }

    #[Test]
    public function effectiveScopeWithBothCodesDefaultsToOwn(): void
    {
        $policy = new OwnershipPolicy($this->resolverWith(['exports.view_own', 'exports.view_all']));

        // Default scope is own — caller must opt in to `all` explicitly.
        self::assertSame(OwnershipScope::Own, $policy->effectiveScope($this->user(), 'exports', null));
        self::assertSame(OwnershipScope::All, $policy->effectiveScope($this->user(), 'exports', OwnershipScope::All));
    }

    #[Test]
    public function effectiveScopeOnAllOnlyRoleStillSeesOwnRows(): void
    {
        // Edge case — Integration Manager has only `*.view_all`; the
        // own subset is implicit (own ⊆ all). They never need to ask
        // for own scope, but if they do (or if it's the default), they
        // get it.
        $policy = new OwnershipPolicy($this->resolverWith(['exports.view_all']));

        self::assertSame(OwnershipScope::Own, $policy->effectiveScope($this->user(), 'exports', null));
        self::assertSame(OwnershipScope::All, $policy->effectiveScope($this->user(), 'exports', OwnershipScope::All));
    }

    #[Test]
    public function multimediaResourceUsesAddEditCodes(): void
    {
        $policy = new OwnershipPolicy($this->resolverWith(['multimedia.add_edit_any']));

        self::assertTrue($policy->canViewAll($this->user(), 'multimedia'));
        self::assertFalse($policy->canViewOwn($this->user(), 'multimedia'));
    }

    #[Test]
    public function apiTokensResourceUsesOwnCrudAndAllViewRevokeCodes(): void
    {
        $policy = new OwnershipPolicy($this->resolverWith(['api_tokens.own.crud']));

        self::assertTrue($policy->canViewOwn($this->user(), 'api_tokens'));
        self::assertFalse($policy->canViewAll($this->user(), 'api_tokens'));
    }

    #[Test]
    public function unknownResourceTypeThrows(): void
    {
        $policy = new OwnershipPolicy($this->resolverWith([]));

        $this->expectException(InvalidArgumentException::class);
        $policy->canViewOwn($this->user(), 'products');
    }

    private function user(): User
    {
        return new User(
            new Tenant('alpha', 'Alpha'),
            'tester@alpha.localhost',
            'placeholder',
            ['ROLE_USER'],
            Uuid::v7(),
        );
    }

    /**
     * @param list<string> $codes
     */
    private function resolverWith(array $codes): PermissionResolverInterface
    {
        $resolver = $this->createStub(PermissionResolverInterface::class);
        $resolver->method('resolve')->willReturn(new PermissionSet($codes));

        return $resolver;
    }
}
