<?php

declare(strict_types=1);

namespace App\Export\Catalog\Domain\Template;

use App\Export\Catalog\Domain\Enum\CatalogTemplateKind;

/**
 * The built-in catalog PDF templates (ADR-0027, CPDF-P2-01). Ships all three
 * archetypes: `sheet` (one product per page), `pricelist` (multi-page price
 * table, CPDF-P6-01) and `grid` (cover + TOC + card grid, CPDF-P6-02).
 * Future kinds raise {@see TemplateNotAvailableException} until their
 * archetype lands. Mirrors
 * {@see \App\Export\Feed\Domain\Template\FeedTemplateCatalog}.
 */
final class CatalogTemplateCatalog
{
    public function get(CatalogTemplateKind $kind): CatalogTemplate
    {
        return match ($kind) {
            CatalogTemplateKind::Sheet => $this->sheet(),
            CatalogTemplateKind::Pricelist => $this->pricelist(),
            CatalogTemplateKind::Grid => $this->grid(),
        };
    }

    /**
     * @return list<CatalogTemplate>
     */
    public function all(): array
    {
        return [$this->sheet(), $this->pricelist(), $this->grid()];
    }

    private function sheet(): CatalogTemplate
    {
        $slots = [
            ['slot' => 'title', 'label' => 'Product name', 'format' => 'text'],
            ['slot' => 'sku', 'label' => 'SKU', 'format' => 'text'],
            ['slot' => 'image', 'label' => 'Main image', 'format' => 'url'],
            ['slot' => 'description', 'label' => 'Description', 'format' => 'richtext'],
            ['slot' => 'price', 'label' => 'Price', 'format' => 'text'],
            // Repeatable label/value rows rendered as a specification table.
            ['slot' => 'specs', 'label' => 'Specification', 'format' => 'list'],
        ];

        $defaultMappings = [
            ['slot' => 'title', 'source' => 'name'],
            ['slot' => 'sku', 'source' => 'sku'],
            ['slot' => 'image', 'source' => 'main_image'],
            ['slot' => 'description', 'source' => 'description'],
            ['slot' => 'price', 'source' => 'price'],
        ];

        return new CatalogTemplate(CatalogTemplateKind::Sheet, 'catalog/sheet.html.twig', $slots, $defaultMappings);
    }

    private function pricelist(): CatalogTemplate
    {
        $slots = [
            ['slot' => 'sku', 'label' => 'SKU', 'format' => 'text'],
            ['slot' => 'name', 'label' => 'Product name', 'format' => 'text'],
            ['slot' => 'price', 'label' => 'Price', 'format' => 'text'],
        ];

        // #2945 — the `availability` column is gone at the operator's
        // request: a printed price list carries prices, and stock read off
        // a PDF is stale the moment it leaves the printer. Dropped from the
        // slot list too, not just the template, so the mapping step stops
        // offering a column that renders nowhere.
        $defaultMappings = [
            ['slot' => 'sku', 'source' => 'sku'],
            ['slot' => 'name', 'source' => 'name'],
            ['slot' => 'price', 'source' => 'price'],
        ];

        return new CatalogTemplate(CatalogTemplateKind::Pricelist, 'catalog/pricelist.html.twig', $slots, $defaultMappings);
    }

    private function grid(): CatalogTemplate
    {
        $slots = [
            ['slot' => 'title', 'label' => 'Product name', 'format' => 'text'],
            ['slot' => 'sku', 'label' => 'SKU', 'format' => 'text'],
            ['slot' => 'image', 'label' => 'Main image', 'format' => 'url'],
            ['slot' => 'price', 'label' => 'Price', 'format' => 'text'],
        ];

        $defaultMappings = [
            ['slot' => 'title', 'source' => 'name'],
            ['slot' => 'sku', 'source' => 'sku'],
            ['slot' => 'image', 'source' => 'main_image'],
            ['slot' => 'price', 'source' => 'price'],
        ];

        return new CatalogTemplate(CatalogTemplateKind::Grid, 'catalog/grid.html.twig', $slots, $defaultMappings);
    }
}
