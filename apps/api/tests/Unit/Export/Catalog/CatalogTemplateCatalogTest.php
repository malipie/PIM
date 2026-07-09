<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Catalog;

use App\Export\Catalog\Domain\Enum\CatalogTemplateKind;
use App\Export\Catalog\Domain\Template\CatalogTemplateCatalog;
use App\Export\Catalog\Domain\Template\TemplateNotAvailableException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CPDF-P2-01 — the built-in catalog template catalog exposes the sheet
 * archetype with its slots and default mappings, and rejects kinds whose
 * archetype is not yet shipped.
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
    public function allReturnsOnlyTheSheet(): void
    {
        $all = new CatalogTemplateCatalog()->all();

        self::assertCount(1, $all);
        self::assertSame(CatalogTemplateKind::Sheet, $all[0]->kind);
    }

    #[Test]
    public function gridIsNotAvailableYet(): void
    {
        $this->expectException(TemplateNotAvailableException::class);
        new CatalogTemplateCatalog()->get(CatalogTemplateKind::Grid);
    }

    #[Test]
    public function pricelistIsNotAvailableYet(): void
    {
        $this->expectException(TemplateNotAvailableException::class);
        new CatalogTemplateCatalog()->get(CatalogTemplateKind::Pricelist);
    }
}
