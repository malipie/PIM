<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Feed;

use App\Export\Feed\Domain\Descriptor\FeedDescriptor;
use App\Export\Feed\Domain\Enum\FeedTemplateKind;
use App\Export\Feed\Domain\Template\FeedTemplateCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * XMLF-P2-02 — every built-in template is a valid descriptor whose default
 * mappings reference real slots.
 */
final class FeedTemplateCatalogTest extends TestCase
{
    /**
     * @return iterable<string, array{FeedTemplateKind}>
     */
    public static function kinds(): iterable
    {
        yield 'google' => [FeedTemplateKind::GoogleShopping];
        yield 'ceneo' => [FeedTemplateKind::Ceneo];
        yield 'meta' => [FeedTemplateKind::Meta];
        yield 'custom' => [FeedTemplateKind::Custom];
    }

    #[Test]
    #[DataProvider('kinds')]
    public function descriptorParsesAndMappingsReferenceRealSlots(FeedTemplateKind $kind): void
    {
        $template = new FeedTemplateCatalog()->get($kind);
        self::assertSame($kind, $template->kind);

        // The seed descriptor must be structurally valid.
        $descriptor = FeedDescriptor::fromArray($template->descriptor);
        self::assertNotEmpty($descriptor->slots);

        // Every default mapping targets a slot that exists in the descriptor.
        foreach ($template->defaultMappings as $mapping) {
            $slot = $mapping['slot'] ?? null;
            self::assertIsString($slot);
            self::assertNotNull($descriptor->findSlot($slot), sprintf('%s: mapping slot "%s" not in descriptor', $kind->value, $slot));
        }
    }

    #[Test]
    public function catalogExposesAllFourKinds(): void
    {
        $all = new FeedTemplateCatalog()->all();
        $kinds = array_map(static fn ($t): FeedTemplateKind => $t->kind, $all);

        self::assertEqualsCanonicalizing(
            [FeedTemplateKind::GoogleShopping, FeedTemplateKind::Ceneo, FeedTemplateKind::Meta, FeedTemplateKind::Custom],
            $kinds,
        );
    }
}
