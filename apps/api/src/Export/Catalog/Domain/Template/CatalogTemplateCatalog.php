<?php

declare(strict_types=1);

namespace App\Export\Catalog\Domain\Template;

use App\Export\Catalog\Domain\Enum\CatalogTemplateKind;

/**
 * The built-in catalog PDF templates (ADR-0027, CPDF-P2-01). Ships the `sheet`
 * archetype (one product per page) and the `pricelist` archetype (multi-page
 * price table, CPDF-P6-01); `grid` arrives in CPDF-P6-02 and raises
 * {@see TemplateNotAvailableException} until then. Mirrors
 * {@see \App\Export\Feed\Domain\Template\FeedTemplateCatalog}.
 */
final class CatalogTemplateCatalog
{
    public function get(CatalogTemplateKind $kind): CatalogTemplate
    {
        return match ($kind) {
            CatalogTemplateKind::Sheet => $this->sheet(),
            CatalogTemplateKind::Pricelist => $this->pricelist(),
            CatalogTemplateKind::Grid => throw new TemplateNotAvailableException(
                sprintf('Catalog template "%s" is not available yet; the grid archetype arrives in CPDF-P6-02.', $kind->value),
            ),
        };
    }

    /**
     * @return list<CatalogTemplate>
     */
    public function all(): array
    {
        return [$this->sheet(), $this->pricelist()];
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
            ['slot' => 'availability', 'label' => 'Availability', 'format' => 'text'],
        ];

        $defaultMappings = [
            ['slot' => 'sku', 'source' => 'sku'],
            ['slot' => 'name', 'source' => 'name'],
            ['slot' => 'price', 'source' => 'price'],
            // The demo/default schema signals availability with the boolean
            // `in_stock` attribute; remappable per profile like every slot.
            ['slot' => 'availability', 'source' => 'in_stock'],
        ];

        return new CatalogTemplate(CatalogTemplateKind::Pricelist, 'catalog/pricelist.html.twig', $slots, $defaultMappings);
    }
}
