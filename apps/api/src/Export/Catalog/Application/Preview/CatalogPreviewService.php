<?php

declare(strict_types=1);

namespace App\Export\Catalog\Application\Preview;

use App\Asset\Contracts\Service\AssetInliner;
use App\Export\Catalog\Application\HtmlValueSanitizer;
use App\Export\Catalog\Domain\Enum\CatalogTemplateKind;
use App\Export\Catalog\Domain\HtmlSlotPolicy;
use App\Export\Catalog\Domain\Mapping\CatalogFieldMapping;
use App\Export\Catalog\Domain\Mapping\CatalogItemMapper;
use App\Export\Catalog\Domain\Template\CatalogTemplateCatalog;
use App\Export\Contracts\CatalogProductScope;
use App\Export\Contracts\CatalogProductValues;
use Twig\Environment;

/**
 * Catalog HTML live-preview (ADR-0027, CPDF-P4-01) — renders the first N sample
 * products through the exact same product-building pipeline as
 * {@see \App\Export\Catalog\Application\CatalogRenderService} (map → per-slot
 * sanitise → specs → Twig archetype), but stops after $limit products and
 * returns the raw HTML string instead of feeding a {@see \App\Export\Contracts\PdfRenderer}.
 *
 * It powers the wizard's live-preview iframe for both a draft (unsaved
 * template kind + mappings) and a saved catalog, in-memory: no CatalogRun, no
 * cache upload, no persistence. Mirrors the STYLE of
 * {@see \App\Export\Feed\Application\Preview\FeedPreviewService}.
 *
 * IMPORTANT: the product-building loop below mirrors CatalogRenderService::render()
 * EXACTLY (slot format resolution, richtext sanitising, scalar-slot exclusion,
 * specs table). The two MUST stay in sync — a change to how a product map is
 * built there has to be reflected here (and vice versa), otherwise the preview
 * drifts from the real PDF. Reuse is not possible without altering
 * CatalogRenderService's behaviour, so the logic is replicated faithfully.
 */
final class CatalogPreviewService
{
    public const int DEFAULT_LIMIT = 3;
    private const int MAX_LIMIT = 10;

    public function __construct(
        private readonly CatalogProductValues $values,
        private readonly CatalogItemMapper $mapper,
        private readonly CatalogTemplateCatalog $templates,
        private readonly HtmlValueSanitizer $sanitizer,
        private readonly Environment $twig,
        private readonly AssetInliner $imageInliner,
    ) {
    }

    /**
     * @param array<mixed, mixed>       $branding
     * @param list<array<mixed, mixed>> $fieldMappings
     *
     * @return array{sample_count: int, html: string}
     */
    public function preview(
        CatalogTemplateKind $kind,
        array $branding,
        array $fieldMappings,
        CatalogProductScope $scope,
        int $limit = self::DEFAULT_LIMIT,
    ): array {
        $limit = max(1, min($limit, self::MAX_LIMIT));

        // Future template kinds raise TemplateNotAvailableException — propagate
        // (the controller maps it to a 422).
        $template = $this->templates->get($kind);
        $mappings = CatalogFieldMapping::listFromArray($fieldMappings);

        // slot => HTML format, from the template definition (richtext gets sanitised + printed raw).
        $slotFormats = [];
        foreach ($template->slots as $slot) {
            $slotFormats[$slot['slot']] = $slot['format'];
        }

        // Attribute codes consumed by a scalar (non-`specs`) slot mapping — these
        // are already surfaced through a dedicated slot, so they must not be
        // duplicated into the generic specification table.
        $scalarSlotRefs = $this->scalarSlotRefs($mappings, $slotFormats);

        /** @var list<array<string, mixed>> $products */
        $products = [];
        $processed = 0;

        foreach ($this->values->forScope($scope) as $row) {
            if ($processed >= $limit) {
                break;
            }

            $slots = $this->mapper->map($mappings, $row);

            $product = [];
            foreach ($template->slots as $slot) {
                $name = $slot['slot'];
                if ('specs' === $name) {
                    continue; // built below from the leftover attributes
                }
                $value = $slots[$name] ?? '';
                $format = $slotFormats[$name] ?? '';
                if ('richtext' === $format) {
                    // Template prints this slot with `|raw` — sanitise here.
                    $value = $this->sanitizer->sanitize($value, HtmlSlotPolicy::RichText);
                } elseif ('url' === $format && '' !== $value) {
                    // #2570 — inline image-slot assets as data: URIs (mirrors
                    // CatalogRenderService so the preview matches the PDF).
                    $value = $this->imageInliner->toDataUri($value) ?? $value;
                }
                // Text / url slots stay raw strings; Twig autoescape handles them.
                $product[$name] = $value;
            }

            if (\array_key_exists('specs', $slotFormats)) {
                $product['specs'] = $this->specs($row, $scalarSlotRefs);
            }

            $products[] = $product;
            ++$processed;
        }

        $html = $this->twig->render($template->twig, [
            'branding' => $this->inlineBrandingLogo($branding),
            'products' => $products,
        ]);

        return [
            'sample_count' => \count($products),
            'html' => $html,
        ];
    }

    /**
     * Inline the branding logo as a data: URI (mirrors
     * {@see \App\Export\Catalog\Application\CatalogRenderService::inlineBrandingLogo()}).
     *
     * @param array<mixed, mixed> $branding
     *
     * @return array<mixed, mixed>
     */
    private function inlineBrandingLogo(array $branding): array
    {
        $logo = $branding['logo'] ?? null;
        if (\is_string($logo) && '' !== $logo) {
            $branding['logo'] = $this->imageInliner->toDataUri($logo) ?? $logo;
        }

        return $branding;
    }

    /**
     * Attribute codes already surfaced through a scalar (non-`specs`) slot, so
     * the specification table can exclude them. Mirrors
     * {@see \App\Export\Catalog\Application\CatalogRenderService::scalarSlotRefs()}.
     *
     * @param list<CatalogFieldMapping> $mappings
     * @param array<string, string>     $slotFormats slot name => template format
     *
     * @return array<string, true>
     */
    private function scalarSlotRefs(array $mappings, array $slotFormats): array
    {
        $refs = [];
        foreach ($mappings as $mapping) {
            if ('attribute' !== $mapping->sourceKind || null === $mapping->sourceRef) {
                continue;
            }
            if ('specs' === $mapping->slot) {
                continue;
            }
            if (!\array_key_exists($mapping->slot, $slotFormats)) {
                continue; // mapping targets a slot the template does not expose
            }
            $refs[$mapping->sourceRef] = true;
        }

        return $refs;
    }

    /**
     * Build the specification rows from the attributes not already consumed by a
     * scalar slot. Mirrors
     * {@see \App\Export\Catalog\Application\CatalogRenderService::specs()}.
     *
     * @param array<string, string> $row
     * @param array<string, true>   $scalarSlotRefs
     *
     * @return list<array{label: string, value: string}>
     */
    private function specs(array $row, array $scalarSlotRefs): array
    {
        $specs = [];
        foreach ($row as $code => $value) {
            if (\array_key_exists($code, $scalarSlotRefs)) {
                continue;
            }
            $specs[] = ['label' => $code, 'value' => $value];
        }

        return $specs;
    }
}
