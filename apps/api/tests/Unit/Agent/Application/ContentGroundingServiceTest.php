<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Application;

use App\Agent\Application\Content\ContentGroundingService;
use App\Agent\Domain\Entity\ContentRecipe;
use App\Catalog\Contracts\Query\ObjectFacts;
use App\Catalog\Contracts\Query\ObjectFactsPort;
use App\Channel\Contracts\ChannelPublicationResolverInterface;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * AICG-P2-01 (#2331) — the grounding algebra: facts = recipe source
 * codes ∩ resolvable values, narrowed by the channel publication
 * allow-list; missing codes reported for the completeness gate;
 * usedCodes mirror the facts exactly (the source_attributes audit).
 */
final class ContentGroundingServiceTest extends TestCase
{
    #[Test]
    public function groundsRecipeSourceCodesAndReportsMissing(): void
    {
        $objectTypeId = Uuid::v7();
        $facts = new ObjectFacts(
            objectId: Uuid::v7(),
            objectTypeId: $objectTypeId,
            values: [
                'material' => ['value' => 'aluminium'],
                'color' => ['option_code' => 'red'],
            ],
            missingCodes: ['size'],
            siblingLocales: ['material' => ['en' => ['value' => 'aluminum']]],
        );

        $grounding = $this->service($facts)->ground(
            $facts->objectId,
            $this->recipe(['material', 'color', 'size']),
            self::tenant(),
            locale: 'pl',
        );

        self::assertSame(['value' => 'aluminium'], $grounding->facts['material']);
        self::assertSame(['option_code' => 'red'], $grounding->facts['color']);
        self::assertSame(['material', 'color'], $grounding->usedCodes);
        self::assertSame(['size'], $grounding->missingCodes);
        self::assertSame(['en' => ['value' => 'aluminum']], $grounding->siblingLocales['material']);
        self::assertSame([], $grounding->channelContext);
        self::assertTrue($grounding->hasFacts());
    }

    #[Test]
    public function channelPublicationAllowListNarrowsTheFactSet(): void
    {
        $facts = new ObjectFacts(
            objectId: Uuid::v7(),
            objectTypeId: Uuid::v7(),
            values: [
                'material' => ['value' => 'aluminium'],
                'internal_notes' => ['value' => 'do not publish'],
            ],
            missingCodes: ['size', 'ean'],
        );
        $publication = $this->createStub(ChannelPublicationResolverInterface::class);
        $publication->method('resolvePublishedCodes')->willReturn(['material', 'size', 'ean']);
        $publication->method('resolvePublishedLocales')->willReturn(['pl', 'en']);

        $grounding = $this->service($facts, $publication)->ground(
            $facts->objectId,
            $this->recipe(['material', 'internal_notes', 'size', 'ean']),
            self::tenant(),
            channel: 'b2c',
        );

        // internal_notes is resolvable but unpublished — out of scope
        // (dropped entirely, not "missing"); size/ean are published and
        // absent — genuinely missing for this reading.
        self::assertSame(['material'], $grounding->usedCodes);
        self::assertArrayNotHasKey('internal_notes', $grounding->facts);
        self::assertSame(['size', 'ean'], $grounding->missingCodes);
        self::assertSame(['channel' => 'b2c', 'published_locales' => ['pl', 'en']], $grounding->channelContext);
    }

    #[Test]
    public function publishAllChannelKeepsEveryResolvedFact(): void
    {
        $facts = new ObjectFacts(
            objectId: Uuid::v7(),
            objectTypeId: Uuid::v7(),
            values: ['material' => ['value' => 'aluminium']],
            missingCodes: [],
        );
        $publication = $this->createStub(ChannelPublicationResolverInterface::class);
        $publication->method('resolvePublishedCodes')->willReturn(null);
        $publication->method('resolvePublishedLocales')->willReturn([]);

        $grounding = $this->service($facts, $publication)->ground(
            $facts->objectId,
            $this->recipe(['material']),
            self::tenant(),
            channel: 'b2b',
        );

        self::assertSame(['material'], $grounding->usedCodes);
        self::assertSame('b2b', $grounding->channelContext['channel']);
    }

    #[Test]
    public function productWithNoResolvableFactsGroundsToEmpty(): void
    {
        $facts = new ObjectFacts(
            objectId: Uuid::v7(),
            objectTypeId: Uuid::v7(),
            values: [],
            missingCodes: ['material', 'color'],
        );

        $grounding = $this->service($facts)->ground(
            $facts->objectId,
            $this->recipe(['material', 'color']),
            self::tenant(),
        );

        self::assertFalse($grounding->hasFacts());
        self::assertSame([], $grounding->usedCodes);
        self::assertSame(['material', 'color'], $grounding->missingCodes);
    }

    /**
     * @param list<string> $sourceAttributes
     */
    private function recipe(array $sourceAttributes): ContentRecipe
    {
        return new ContentRecipe(
            code: 'product_description',
            name: 'Opis produktu',
            targetAttribute: 'description',
            sourceAttributes: $sourceAttributes,
        );
    }

    private function service(
        ObjectFacts $facts,
        ?ChannelPublicationResolverInterface $publication = null,
    ): ContentGroundingService {
        $port = $this->createStub(ObjectFactsPort::class);
        $port->method('facts')->willReturn($facts);

        return new ContentGroundingService(
            $port,
            $publication ?? $this->createStub(ChannelPublicationResolverInterface::class),
        );
    }

    private static function tenant(): Tenant
    {
        return new Tenant('demo-test-'.bin2hex(random_bytes(2)), 'Demo');
    }
}
