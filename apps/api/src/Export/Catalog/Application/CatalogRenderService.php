<?php

declare(strict_types=1);

namespace App\Export\Catalog\Application;

use App\Export\Catalog\Domain\Entity\CatalogProfile;
use App\Export\Catalog\Domain\HtmlSlotPolicy;
use App\Export\Catalog\Domain\Mapping\CatalogFieldMapping;
use App\Export\Catalog\Domain\Mapping\CatalogItemMapper;
use App\Export\Catalog\Domain\Template\CatalogTemplateCatalog;
use App\Export\Contracts\CatalogProductScope;
use App\Export\Contracts\CatalogProductValues;
use App\Export\Contracts\PdfRenderer;
use App\Export\Contracts\PdfRenderOptions;
use Closure;
use Twig\Environment;

/**
 * Orchestrates one catalog regeneration for the shipped archetypes (ADR-0027,
 * CPDF-P2-03): resolves the profile's template, streams the memory-bounded
 * product value maps ({@see CatalogProductValues}), applies the field mappings
 * ({@see CatalogItemMapper}), sanitises rich-text slots ({@see HtmlValueSanitizer}),
 * renders the Twig archetype to HTML, then to a PDF binary through the
 * {@see PdfRenderer} port, and writes it to $targetPath. Mirrors the STYLE of
 * {@see \App\Export\Feed\Application\Generator\FeedGenerator}, but produces a PDF
 * document instead of streaming XML.
 *
 * Memory: the value source is memory-bounded (chunked ExportBuilder +
 * EntityManager::clear()), but Dompdf accumulates the whole document in memory,
 * so large catalogs are capped/chunked in CPDF-P6-04 (decision A). Image
 * embedding as a data-URI is refined in CPDF-P6-03 (decision B) — for now the
 * resolved image value is passed straight through.
 */
final class CatalogRenderService
{
    /** Progress-callback cadence — a coarse hook for the async run (CPDF-P3-01). */
    private const int PROGRESS_EVERY = 50;

    public function __construct(
        private readonly CatalogProductValues $values,
        private readonly CatalogItemMapper $mapper,
        private readonly CatalogTemplateCatalog $templates,
        private readonly HtmlValueSanitizer $sanitizer,
        private readonly Environment $twig,
        private readonly PdfRenderer $renderer,
    ) {
    }

    /**
     * @param Closure(int): void|null $onChunk invoked with the processed-product count every
     *                                         PROGRESS_EVERY products when provided
     */
    public function render(CatalogProfile $profile, string $targetPath, ?Closure $onChunk = null): CatalogRenderResult
    {
        // grid raises TemplateNotAvailableException until CPDF-P6-02 — propagate.
        $template = $this->templates->get($profile->getTemplateKind());
        $mappings = CatalogFieldMapping::listFromArray($profile->getFieldMappings());
        $scope = $this->scope($profile, $mappings);

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
            $slots = $this->mapper->map($mappings, $row);

            $product = [];
            foreach ($template->slots as $slot) {
                $name = $slot['slot'];
                if ('specs' === $name) {
                    continue; // built below from the leftover attributes
                }
                $value = $slots[$name] ?? '';
                if ('richtext' === ($slotFormats[$name] ?? '')) {
                    // Template prints this slot with `|raw` — sanitise here.
                    $value = $this->sanitizer->sanitize($value, HtmlSlotPolicy::RichText);
                }
                // Text / url slots stay raw strings; Twig autoescape handles them.
                $product[$name] = $value;
            }

            if (\array_key_exists('specs', $slotFormats)) {
                $product['specs'] = $this->specs($row, $scalarSlotRefs);
            }

            $products[] = $product;
            ++$processed;
            $this->tickProgress($onChunk, $processed);
        }

        $html = $this->twig->render($template->twig, [
            'branding' => $profile->getBranding(),
            'products' => $products,
        ]);

        $pdf = $this->renderer->render($html, new PdfRenderOptions());
        file_put_contents($targetPath, $pdf);

        return new CatalogRenderResult(
            pageCount: $this->countPages($pdf, \count($products)),
            byteSize: \strlen($pdf),
            itemCount: \count($products),
        );
    }

    /**
     * The attribute codes the values adapter must fetch: the distinct
     * `sourceRef` of every mapping whose source is a PIM attribute (mirrors the
     * FeedGenerator scope logic).
     *
     * @param list<CatalogFieldMapping> $mappings
     */
    private function scope(CatalogProfile $profile, array $mappings): CatalogProductScope
    {
        $codes = [];
        foreach ($mappings as $mapping) {
            if ('attribute' === $mapping->sourceKind && null !== $mapping->sourceRef) {
                $codes[$mapping->sourceRef] = true;
            }
        }

        return new CatalogProductScope(
            objectTypeId: $profile->getObjectTypeId(),
            attributeCodes: array_keys($codes),
            locale: $profile->getLocale(),
            channel: $profile->getPublicationChannel(),
            filter: $profile->getFilter(),
        );
    }

    /**
     * Attribute codes already surfaced through a scalar (non-`specs`) slot, so
     * the specification table can exclude them.
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
     * scalar slot. Label is the attribute code; the raw serialized value is left
     * for Twig to escape in the specs table.
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

    /**
     * @param Closure(int): void|null $onChunk
     */
    private function tickProgress(?Closure $onChunk, int $processed): void
    {
        if (null !== $onChunk && $processed > 0 && 0 === $processed % self::PROGRESS_EVERY) {
            $onChunk($processed);
        }
    }

    /**
     * Lightweight page-count heuristic: Dompdf emits one `/Type /Page` object per
     * page and a single `/Type /Pages` tree node, so the page count is the number
     * of `/Type /Page` occurrences minus the `/Type /Pages` node. If the count
     * comes out 0 (e.g. an unexpected PDF structure), fall back to the item count
     * (one sheet per product), never below 1.
     */
    private function countPages(string $pdf, int $itemCount): int
    {
        $pages = substr_count($pdf, '/Type /Page') - substr_count($pdf, '/Type /Pages');
        if ($pages < 1) {
            return max(1, $itemCount);
        }

        return $pages;
    }
}
