<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Feed;

use App\Export\Feed\Application\Template\FeedProfileFactory;
use App\Export\Feed\Domain\Descriptor\FeedDescriptor;
use App\Export\Feed\Domain\Enum\FeedTemplateKind;
use App\Export\Feed\Domain\Template\FeedTemplateCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * XMLF-P2-06 — creating a feed from a built-in template clones its descriptor
 * and default mappings into an editable profile.
 */
final class FeedProfileFactoryTest extends TestCase
{
    #[Test]
    public function clonesTemplateDescriptorAndMappings(): void
    {
        $factory = new FeedProfileFactory(new FeedTemplateCatalog());
        $objectTypeId = Uuid::v7();

        $profile = $factory->fromTemplate(
            FeedTemplateKind::GoogleShopping,
            'google_pl',
            'Google Shopping — PL',
            $objectTypeId,
            locale: 'pl',
            currency: 'PLN',
        );

        self::assertSame('google_pl', $profile->getCode());
        self::assertSame(FeedTemplateKind::GoogleShopping, $profile->getTemplateKind());
        self::assertSame('pl', $profile->getLocale());
        self::assertSame('PLN', $profile->getCurrency());
        self::assertTrue($profile->getObjectTypeId()->equals($objectTypeId));

        // The cloned descriptor is a valid, parseable feed structure.
        $descriptor = FeedDescriptor::fromArray($profile->getDescriptor());
        self::assertSame('rss', $descriptor->rootElement);
        self::assertNotNull($descriptor->findSlot('g:id'));
        self::assertNotEmpty($profile->getFieldMappings());
    }

    #[Test]
    public function customTemplateProducesBlankStarter(): void
    {
        $profile = new FeedProfileFactory(new FeedTemplateCatalog())
            ->fromTemplate(FeedTemplateKind::Custom, 'b2b', 'Feed B2B', Uuid::v7());

        self::assertSame(FeedTemplateKind::Custom, $profile->getTemplateKind());
        $descriptor = FeedDescriptor::fromArray($profile->getDescriptor());
        self::assertSame('products', $descriptor->rootElement);
    }
}
