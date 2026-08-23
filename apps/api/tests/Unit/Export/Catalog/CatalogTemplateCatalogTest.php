<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Catalog;

use App\Export\Catalog\Domain\Enum\CatalogTemplateKind;
use App\Export\Catalog\Domain\Template\CatalogTemplateCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CPDF-P2-01 / CPDF-P6-01 / CPDF-P6-02 — the built-in catalog template catalog
 * exposes the sheet, pricelist and grid archetypes with their slots and
 * default mappings.
 */
final class CatalogTemplateCatalogTest extends TestCase
{
    #[Test]
    public function sheetExposesSixSlotsWithDefaultMappings(): void
    {
        $template = new CatalogTemplateCatalog()->get(CatalogTemplateKind::Sheet);

        self::assertSame(CatalogTemplateKind::Sheet, $template->kind);
        self::assertSame('catalog/sheet.html.twig', $template->twig);
        self::assertSame(
            ['title', 'sku', 'image', 'description', 'price', 'specs'],
            $template->slotNames(),
        );
        self::assertCount(6, $template->slots);
        self::assertCount(5, $template->defaultMappings);
        self::assertContains(['slot' => 'title', 'source' => 'name'], $template->defaultMappings);
        self::assertContains(['slot' => 'image', 'source' => 'main_image'], $template->defaultMappings);
    }

    #[Test]
    public function pricelistExposesThreeSlotsWithoutAvailability(): void
    {
        $template = new CatalogTemplateCatalog()->get(CatalogTemplateKind::Pricelist);

        self::assertSame(CatalogTemplateKind::Pricelist, $template->kind);
        self::assertSame('catalog/pricelist.html.twig', $template->twig);
        // #2945 — availability dropped at the operator's request. Pinned on
        // the slot list, not only the template: a slot offered in the mapping
        // step but rendered nowhere is worse than no slot at all.
        self::assertSame(['sku', 'name', 'price'], $template->slotNames());
        self::assertCount(3, $template->defaultMappings);
        self::assertContains(['slot' => 'price', 'source' => 'price'], $template->defaultMappings);
        self::assertNotContains(['slot' => 'availability', 'source' => 'in_stock'], $template->defaultMappings);
    }

    #[Test]
    public function gridExposesFourSlotsWithDefaultMappings(): void
    {
        $template = new CatalogTemplateCatalog()->get(CatalogTemplateKind::Grid);

        self::assertSame(CatalogTemplateKind::Grid, $template->kind);
        self::assertSame('catalog/grid.html.twig', $template->twig);
        self::assertSame(['title', 'sku', 'image', 'price'], $template->slotNames());
        self::assertCount(4, $template->defaultMappings);
        self::assertContains(['slot' => 'title', 'source' => 'name'], $template->defaultMappings);
        self::assertContains(['slot' => 'image', 'source' => 'main_image'], $template->defaultMappings);
    }

    #[Test]
    public function allReturnsEveryArchetype(): void
    {
        $all = new CatalogTemplateCatalog()->all();

        self::assertCount(3, $all);
        self::assertSame(CatalogTemplateKind::Sheet, $all[0]->kind);
        self::assertSame(CatalogTemplateKind::Pricelist, $all[1]->kind);
        self::assertSame(CatalogTemplateKind::Grid, $all[2]->kind);
    }

    #[Test]
    public function everyKindMapsToItsOwnTwigTemplate(): void
    {
        $catalog = new CatalogTemplateCatalog();
        $paths = [];
        foreach (CatalogTemplateKind::cases() as $kind) {
            $template = $catalog->get($kind);
            $paths[$kind->value] = $template->twig;
            self::assertStringContainsString('/'.$kind->value.'.html.twig', '/'.$template->twig);
        }

        self::assertCount(\count(CatalogTemplateKind::cases()), array_unique($paths));
    }
}
