<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application\Policy;

use App\Identity\Application\PermissionResolverInterface;
use App\Identity\Application\Policy\LocaleChannelScopePolicy;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Rbac\PermissionSet;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * RBAC-P3-009 (#672) — LocaleChannelScopePolicy unit coverage of the
 * wildcard / narrowed / union conventions.
 */
final class LocaleChannelScopePolicyTest extends TestCase
{
    #[Test]
    public function emptyScopeAllowsEveryLocale(): void
    {
        $policy = new LocaleChannelScopePolicy($this->resolverWith(
            localeScope: [],
            channelScope: [],
        ));

        self::assertTrue($policy->canEditLocale($this->user(), 'pl'));
        self::assertTrue($policy->canEditLocale($this->user(), 'en'));
        self::assertTrue($policy->canEditChannel($this->user(), 'shopify'));
    }

    #[Test]
    public function wildcardAllowsEveryLocale(): void
    {
        $policy = new LocaleChannelScopePolicy($this->resolverWith(
            localeScope: [LocaleChannelScopePolicy::WILDCARD],
            channelScope: [LocaleChannelScopePolicy::WILDCARD],
        ));

        self::assertTrue($policy->canEditLocale($this->user(), 'pl'));
        self::assertTrue($policy->canEditChannel($this->user(), 'shopify'));
    }

    #[Test]
    public function narrowedLocaleAllowsOnlyListed(): void
    {
        $policy = new LocaleChannelScopePolicy($this->resolverWith(
            localeScope: ['en'],
            channelScope: [],
        ));

        self::assertTrue($policy->canEditLocale($this->user(), 'en'));
        self::assertFalse($policy->canEditLocale($this->user(), 'pl'));
    }

    #[Test]
    public function narrowedChannelAllowsOnlyListed(): void
    {
        $policy = new LocaleChannelScopePolicy($this->resolverWith(
            localeScope: [],
            channelScope: ['allegro'],
        ));

        self::assertTrue($policy->canEditChannel($this->user(), 'allegro'));
        self::assertFalse($policy->canEditChannel($this->user(), 'shopify'));
    }

    #[Test]
    public function canEditValueRequiresBothDimensionsToPass(): void
    {
        $policy = new LocaleChannelScopePolicy($this->resolverWith(
            localeScope: ['en'],
            channelScope: ['shopify'],
        ));

        self::assertTrue($policy->canEditValue($this->user(), 'en', 'shopify'));
        self::assertFalse($policy->canEditValue($this->user(), 'pl', 'shopify'));
        self::assertFalse($policy->canEditValue($this->user(), 'en', 'allegro'));
        self::assertFalse($policy->canEditValue($this->user(), 'pl', 'allegro'));
    }

    #[Test]
    public function unionAcrossMultipleLocalesAllowsAny(): void
    {
        // The resolver already aggregates roles into one PermissionSet —
        // a user with two roles narrowed to ["en"] and ["pl"] respectively
        // sees the union ["en", "pl"].
        $policy = new LocaleChannelScopePolicy($this->resolverWith(
            localeScope: ['en', 'pl'],
            channelScope: [],
        ));

        self::assertTrue($policy->canEditLocale($this->user(), 'en'));
        self::assertTrue($policy->canEditLocale($this->user(), 'pl'));
        self::assertFalse($policy->canEditLocale($this->user(), 'de'));
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
     * @param list<string> $localeScope
     * @param list<string> $channelScope
     */
    private function resolverWith(array $localeScope, array $channelScope): PermissionResolverInterface
    {
        $resolver = $this->createStub(PermissionResolverInterface::class);
        $resolver->method('resolve')->willReturn(new PermissionSet(
            permissionCodes: ['products.edit'],
            localeScope: $localeScope,
            channelScope: $channelScope,
        ));

        return $resolver;
    }
}
