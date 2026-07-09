<?php

declare(strict_types=1);

namespace App\Export\Catalog\Domain\Template;

use App\Export\Catalog\Domain\Enum\CatalogTemplateKind;

/**
 * The built-in catalog PDF templates (ADR-0027, CPDF-P2-01). MVP ships the
 * `sheet` archetype (one product per page); `grid` and `pricelist` arrive in
 * M6 and raise {@see TemplateNotAvailableException} until then. Mirrors
 * {@see \App\Export\Feed\Domain\Template\FeedTemplateCatalog}.
 */
final class CatalogTemplateCatalog
{
    public function get(CatalogTemplateKind $kind): CatalogTemplate
    {
        return match ($kind) {
            CatalogTemplateKind::Sheet => $this->sheet(),
            CatalogTemplateKind::Grid, CatalogTemplateKind::Pricelist => throw new TemplateNotAvailableException(
                sprintf('Catalog template "%s" is not available yet; grid and pricelist archetypes arrive in M6.', $kind->value),
            ),
        };
    }

    /**
     * @return list<CatalogTemplate>
     */
    public function all(): array
    {
        return [$this->sheet()];
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
}
