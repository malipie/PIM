<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Application\Filter\FilterScopeResolver;
use App\Channel\Contracts\ScopeEnumeratorInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * #2673 — scope resolution: channel code → uuid, locale membership, and the
 * loud 400s validate() relies on.
 */
final class FilterScopeResolverTest extends TestCase
{
    private const string CHANNEL_ID = '0198c9a0-0000-7000-8000-0000000000aa';

    public function testNullAndEmptyScopeResolveToNull(): void
    {
        $resolver = $this->resolver();

        self::assertNull($resolver->resolve(null));
        self::assertNull($resolver->resolve([]));
        self::assertNull($resolver->resolve(['channel' => null, 'locale' => null]));
    }

    public function testChannelCodeResolvesToUuid(): void
    {
        $context = $this->resolver()->resolve(['channel' => 'shopify']);

        self::assertNotNull($context);
        self::assertSame(self::CHANNEL_ID, $context->channelId);
        self::assertSame('shopify', $context->channelCode);
        self::assertNull($context->locale);
        self::assertFalse($context->isEmpty());
    }

    public function testLocaleResolves(): void
    {
        $context = $this->resolver()->resolve(['locale' => 'pl']);

        self::assertNotNull($context);
        self::assertNull($context->channelId);
        self::assertSame('pl', $context->locale);
    }

    public function testUnknownChannelThrows400WithAvailableCodes(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessageMatches('/Unknown channel code "amazon".*shopify/');

        $this->resolver()->resolve(['channel' => 'amazon']);
    }

    public function testInactiveLocaleThrows400(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessageMatches('/Locale "de" is not active.*pl, en/');

        $this->resolver()->resolve(['locale' => 'de']);
    }

    public function testMalformedScopeThrows400(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->resolver()->resolve('shopify');
    }

    private function resolver(): FilterScopeResolver
    {
        $scopes = $this->createStub(ScopeEnumeratorInterface::class);
        $scopes->method('channelIdsByCode')->willReturn(['shopify' => self::CHANNEL_ID]);
        $scopes->method('localeShortCodes')->willReturn(['pl', 'en']);

        $tenantContext = new TenantContext();
        $tenantContext->set(new Tenant('unit', 'Unit Tenant'));

        return new FilterScopeResolver($scopes, $tenantContext);
    }
}
