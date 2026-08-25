<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\Application\Tool\AttributeGroupIdentifierResolver;
use App\Catalog\Contracts\Query\AttributeGroupSummary;
use App\Catalog\Contracts\Service\AttributeGroupCatalogReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AttributeGroupIdentifierResolverTest extends TestCase
{
    #[Test]
    public function resolvesLocalizedLabelToCanonicalCode(): void
    {
        $tenantId = Uuid::v7();
        $reader = new class($tenantId) implements AttributeGroupCatalogReader {
            public function __construct(private readonly Uuid $tenantId)
            {
            }

            public function findAllByTenant(Uuid $tenantId): array
            {
                return [new AttributeGroupSummary(Uuid::v7(), $this->tenantId, 'pricing', ['pl' => 'Ceny', 'en' => 'Pricing'])];
            }
        };

        $result = new AttributeGroupIdentifierResolver($reader)->resolve(['Ceny'], $tenantId);

        self::assertSame(['pricing'], $result['codes']);
        self::assertSame([], $result['unresolved']);
        self::assertSame([], $result['ambiguous']);
    }

    #[Test]
    public function refusesUnknownAndAmbiguousLabels(): void
    {
        $tenantId = Uuid::v7();
        $reader = new class($tenantId) implements AttributeGroupCatalogReader {
            public function __construct(private readonly Uuid $tenantId)
            {
            }

            public function findAllByTenant(Uuid $tenantId): array
            {
                return [
                    new AttributeGroupSummary(Uuid::v7(), $this->tenantId, 'pricing-retail', ['pl' => 'Ceny']),
                    new AttributeGroupSummary(Uuid::v7(), $this->tenantId, 'pricing-wholesale', ['pl' => 'Ceny']),
                ];
            }
        };

        $result = new AttributeGroupIdentifierResolver($reader)->resolve(['Ceny', 'Nie istnieje'], $tenantId);

        self::assertSame([], $result['codes']);
        self::assertSame(['Nie istnieje'], $result['unresolved']);
        self::assertSame(['Ceny' => ['pricing-retail', 'pricing-wholesale']], $result['ambiguous']);
    }
}
