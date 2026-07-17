<?php

declare(strict_types=1);

namespace App\Export\Catalog\Application;

use App\Asset\Contracts\Service\AssetInliner;
use App\Export\Catalog\Domain\Entity\CatalogProfile;
use App\Export\Catalog\Domain\Enum\CatalogTemplateKind;
use App\Export\Catalog\Domain\HtmlSlotPolicy;
use App\Export\Catalog\Domain\Mapping\CatalogFieldMapping;
use App\Export\Catalog\Domain\Mapping\CatalogItemMapper;
use App\Export\Catalog\Domain\Template\CatalogTemplateCatalog;
use App\Export\Contracts\CatalogProductScope;
use App\Export\Contracts\CatalogProductValues;
use App\Export\Contracts\PdfRenderer;
use App\Export\Contracts\PdfRenderOptions;
use Closure;
use Symfony\Component\Uid\Uuid;
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
 * so renderers that answer false to {@see PdfRenderer::supportsLargeDocuments()}
 * get a product cap ($maxInMemoryItems, env CATALOG_PDF_MAX_ITEMS — decision A,
 * CPDF-P6-04): exceeding it raises {@see CatalogTooLargeException} with an
 * actionable "enable Gotenberg" message instead of an OOM'd worker.
 *
 * Images (#2569/#2570): the default Dompdf renderer runs with remote fetching
 * off, so image-slot values (a bare asset UUID) and the branding logo (a
 * `/api/assets/{id}/preview` URL) are inlined as `data:` URIs through the
 * {@see AssetInliner} seam before rendering — otherwise the renderer silently
 * drops every image. Non-asset URLs pass through untouched; an asset reference
 * the inliner cannot resolve collapses to '' so the template renders its
 * styled placeholder instead of a broken-image box (#2608).
 *
 * Image sharpness (#2608): inlining happens AFTER the streaming loop (the
 * product list is buffered in memory anyway), when the item count is known.
 * Sheet catalogs — one page-filling image per product — upgrade from the
 * thumb (200px) to the medium (800px) variant when the renderer streams
 * (Gotenberg) or the count stays within $hqImageMaxItems. Raw-bitmap budget
 * for the in-process renderer: 48 x 800x800x3 B ~= 92 MB, inside the 256 MB
 * worker ceiling that OOM'd the medium-everywhere attempt (#2601). Grid and
 * pricelist render small images, so thumb stays optimal there.
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
        private readonly AssetInliner $imageInliner,
        private readonly CatalogPdfChromeFactory $chromeFactory,
        private readonly int $maxInMemoryItems = 500,
        private readonly int $hqImageMaxItems = 48,
    ) {
    }

    /**
     * @param Closure(int): void|null $onChunk invoked with the processed-product count every
     *                                         PROGRESS_EVERY products when provided
     */
    public function render(CatalogProfile $profile, string $targetPath, ?Closure $onChunk = null): CatalogRenderResult
    {
        // Future template kinds raise TemplateNotAvailableException — propagate.
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
        $capped = !$this->renderer->supportsLargeDocuments();

        foreach ($this->values->forScope($scope) as $row) {
            if ($capped && $processed >= $this->maxInMemoryItems) {
                // Stop before the in-process renderer OOMs the worker
                // (decision A, CPDF-P6-04) — the async handler records the
                // message on the run.
                throw CatalogTooLargeException::forCap($this->maxInMemoryItems);
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
                }
                // Text slots stay raw strings; Twig autoescape handles them.
                // Url (image) slots keep the raw reference here — they are
                // inlined after the loop, once the item count is known (#2608).
                $product[$name] = $value;
            }

            if (\array_key_exists('specs', $slotFormats)) {
                $product['specs'] = $this->specs($row, $scalarSlotRefs);
            }

            $products[] = $product;
            ++$processed;
            $this->tickProgress($onChunk, $processed);
        }

        $this->inlineProductImages($products, $slotFormats, $template->kind);

        $html = $this->twig->render($template->twig, [
            'branding' => $this->inlineBrandingLogo($profile->getBranding()),
            'products' => $products,
            // Palette / fonts / labels / generated_at / title / item_count —
            // the same chrome the wizard preview renders (#2608).
            ...$this->chromeFactory->chrome(
                $profile->getBranding(),
                $profile->getLocale(),
                \count($products),
                $profile->getName(),
            ),
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
     * Inline the branding logo (a `/api/assets/{id}/preview` URL) as a data:
     * URI so the offline renderer embeds it; a non-asset value passes through.
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
     * Inline every url-format slot as a data: URI, choosing the image variant
     * from the now-known item count (see the class docblock, #2608).
     *
     * @param list<array<string, mixed>> $products
     * @param array<string, string>      $slotFormats slot name => template format
     */
    private function inlineProductImages(array &$products, array $slotFormats, CatalogTemplateKind $kind): void
    {
        $urlSlots = array_keys(array_filter($slotFormats, static fn (string $format): bool => 'url' === $format));
        if ([] === $urlSlots) {
            return;
        }

        $preferredVariant = $this->imageVariant($kind, \count($products));
        foreach ($products as &$product) {
            foreach ($urlSlots as $slot) {
                $value = $product[$slot] ?? '';
                $product[$slot] = \is_string($value) ? $this->inlineImage($value, $preferredVariant) : '';
            }
        }
        unset($product);
    }

    /**
     * Sheet pages are carried by one large image, so they upgrade to the
     * medium (800px) variant when memory allows: always for streaming
     * renderers (Gotenberg), under $hqImageMaxItems (0 disables) for the
     * in-process one. Null keeps the inliner's memory-first thumb order.
     */
    private function imageVariant(CatalogTemplateKind $kind, int $itemCount): ?string
    {
        if (CatalogTemplateKind::Sheet !== $kind) {
            return null;
        }
        if ($this->renderer->supportsLargeDocuments()) {
            return AssetInliner::VARIANT_MEDIUM;
        }
        if ($this->hqImageMaxItems > 0 && $itemCount <= $this->hqImageMaxItems) {
            return AssetInliner::VARIANT_MEDIUM;
        }

        return null;
    }

    /**
     * A resolvable asset reference becomes a data: URI; an unresolvable one
     * collapses to '' (the template renders its placeholder — no more
     * broken-image boxes, #2608); anything else (an external URL) passes
     * through untouched.
     */
    private function inlineImage(string $value, ?string $preferredVariant): string
    {
        if ('' === $value) {
            return '';
        }

        $dataUri = $this->imageInliner->toDataUri($value, $preferredVariant);
        if (null !== $dataUri) {
            return $dataUri;
        }

        return $this->looksLikeAssetReference($value) ? '' : $value;
    }

    private function looksLikeAssetReference(string $value): bool
    {
        return Uuid::isValid($value) || str_contains($value, '/api/assets/');
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
