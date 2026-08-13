<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Controller;

use App\Catalog\Application\AttributesIndexedRebuilder;
use App\Catalog\Application\BulkContext;
use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Provenance;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectValueRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Shared\Application\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * UI-02.6 (#296) — `POST /api/products/{master_id}/generate-variants`.
 *
 * Generates child product rows (variants) for a master row by walking
 * the cartesian product of axis values. Body schema:
 *   - `axes` (required) — `{color: ["red","blue"], size: ["S","M","L"]}`.
 *   - `sku_template` (optional) — default `{master_sku}-{values_joined}`,
 *     e.g. `TST-001-RED-S`.
 *
 * Each generated variant is a `CatalogObject` with:
 *   - `objectType` and `kind=product` inherited from master,
 *   - `parent` set to master (existing self-FK semantics —
 *     `kind='product'` parent_id is the variant master per the
 *     `CatalogObject` docblock),
 *   - `code` derived from the SKU template.
 *
 * The master also gets its `variant_axes` JSONB column updated so the
 * UI-02.18 axes editor reads back the canonical definition. SKU
 * collisions are skipped (counted but not persisted) — re-running the
 * endpoint after a partial failure is idempotent.
 *
 * Each generated variant inherits master attribute values 1:1 with
 * `Provenance::Manual` (non-axis attributes are direct-copied with
 * `channel_id` + `locale` scope preserved). Axis values are stamped
 * fresh on top so they take precedence over whatever the master had
 * on those axis attributes. The axes editor (#308) ensures axis codes
 * resolve to registered `Attribute`s — unknown codes return 400 to
 * fail fast.
 *
 * Performance shape (fix from white-screen incident 2026-05-13):
 *   - `BulkContext::setBulk(true)` for the whole loop — the synchronous
 *     `AttributesIndexedSyncListener` would otherwise re-flush per row
 *     and turn each variant into N×(persist + listener-rebuild) flushes.
 *   - Master `ObjectValue` rows are fetched once up front, not per
 *     combination.
 *   - Persists are batched and committed with a single `$em->flush()`
 *     inside a `wrapInTransaction()` block — partial failures roll back
 *     instead of leaving orphan variants.
 *   - After the bulk flush, `attributes_indexed` is rebuilt for each
 *     fresh variant (the sync listener is muted by `BulkContext`) so
 *     the read path serves the denormalised cache immediately.
 *
 * Out of MVP slice (Faza 1+): axes editor PATCH, master-with-variants
 * delete protection, `Attribute.level=master|variant` enum,
 * `VariantValueResolver` inheritance service (replaces direct copy with
 * reactive `inherited` provenance — schema change required).
 */
final class GenerateVariantsController
{
    private const string UUID_REGEX = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        private readonly CatalogObjectRepositoryInterface $objects,
        private readonly ObjectValueRepositoryInterface $values,
        private readonly AttributeRepositoryInterface $attributes,
        private readonly TenantContext $tenantContext,
        private readonly BulkContext $bulkContext,
        private readonly AttributesIndexedRebuilder $rebuilder,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route(
        '/api/products/{master_id}/generate-variants',
        name: 'pim_products_generate_variants',
        requirements: ['master_id' => self::UUID_REGEX],
        methods: ['POST'],
        priority: 200,
    )]
    #[Route(
        '/api/objects/{master_id}/generate-variants',
        name: 'pim_objects_generate_variants',
        requirements: ['master_id' => self::UUID_REGEX],
        methods: ['POST'],
        priority: 200,
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'products', action: 'add')]
    public function __invoke(string $master_id, Request $request): JsonResponse
    {
        // Generation can stamp dozens of ObjectValue rows per combination;
        // the 30s default would cut moderate runs (e.g. 3 axes × 4 values ×
        // 20 attributes) mid-flush.
        //
        // #2843 — this used to raise the ceiling to 120s, which is the same
        // guess as 30 only further out: the number holds until the machine
        // is busy. It surfaced when the catalog suite started running four
        // processes in parallel and the identical generation blew past 120s,
        // killing the whole worker with a fatal error instead of failing one
        // request. On a loaded instance — an import running, a 50k catalog —
        // the same thing would happen in production, and a fatal error is
        // the least useful way for it to happen. Bulk handlers next door
        // (AbstractBulkHandler, PendingBatchCommitter, BulkRollbackHandler)
        // all pass 0 for exactly this reason; this is a bulk operation too.
        set_time_limit(0);

        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new BadRequestHttpException('No tenant context.');
        }

        // UP-04 (#1021) — capability gate replaces the product-only kind
        // check. Built-in product is seeded `hasVariants=true`, so the
        // legacy `/api/products/...` route keeps working without regression.
        // Custom kinds with `hasVariants=true` (operator opted in via the
        // modeling wizard) now reach the same code path via
        // `/api/objects/...`.
        $master = $this->objects->findById(Uuid::fromString($master_id));
        if (!$master instanceof CatalogObject) {
            throw new NotFoundHttpException(\sprintf('Master object %s not found.', $master_id));
        }
        if (!$master->getObjectType()->hasVariants()) {
            throw new UnprocessableEntityHttpException(\sprintf(
                'ObjectType "%s" does not have variants enabled; flip the hasVariants capability flag in the modeling wizard before generating variants.',
                $master->getObjectType()->getCode(),
            ));
        }
        if (null !== $master->getParent()) {
            throw new ConflictHttpException('Cannot generate variants from a variant — pick the master row.');
        }

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        $axesRaw = $body['axes'] ?? null;
        if (!\is_array($axesRaw) || [] === $axesRaw) {
            throw new BadRequestHttpException('axes must be a non-empty object {axis_code: [values]}.');
        }
        /** @var array<string, list<scalar>> $axes */
        $axes = [];
        foreach ($axesRaw as $axisCode => $values) {
            if (!\is_array($values) || [] === $values) {
                throw new BadRequestHttpException(\sprintf('axes.%s must be a non-empty list of values.', $axisCode));
            }
            $clean = [];
            foreach ($values as $v) {
                if (\is_scalar($v)) {
                    $clean[] = $v;
                }
            }
            if ([] === $clean) {
                throw new BadRequestHttpException(\sprintf('axes.%s must contain scalar values.', $axisCode));
            }
            $axes[(string) $axisCode] = $clean;
        }

        $skuTemplate = $body['sku_template'] ?? null;

        // Fail fast: every axis code must resolve to a registered Attribute on
        // this tenant's schema, otherwise the generated variants would silently
        // miss their axis ObjectValue stamp on save below. Keep the resolved
        // Attribute around so the stamp loop skips a redundant lookup.
        /** @var array<string, Attribute> $axisAttributes */
        $axisAttributes = [];
        foreach (array_keys($axes) as $axisCode) {
            $axisAttribute = $this->attributes->findByCode($axisCode, $tenant);
            if (!$axisAttribute instanceof Attribute) {
                throw new BadRequestHttpException(\sprintf(
                    'Axis code "%s" is not a registered attribute on this object type.',
                    $axisCode,
                ));
            }
            $axisAttributes[$axisCode] = $axisAttribute;
        }

        $combinations = $this->cartesianProduct($axes);

        // Pre-fetch master values once. The previous shape called this inside
        // the combinations loop, turning a 6-combination run with 20 master
        // values into 120 queries instead of 1.
        $masterValues = $this->values->findByObject($master);

        $created = [];
        $skipped = [];
        /** @var list<CatalogObject> $createdVariants */
        $createdVariants = [];

        $this->bulkContext->setBulk(true);
        try {
            $this->em->wrapInTransaction(function () use (
                $master,
                $combinations,
                $skuTemplate,
                $axisAttributes,
                $masterValues,
                $tenant,
                &$created,
                &$skipped,
                &$createdVariants,
                $axes,
            ): void {
                foreach ($combinations as $combination) {
                    $sku = $this->renderSku(
                        $master->getCode(),
                        $combination,
                        \is_string($skuTemplate) ? $skuTemplate : null,
                    );
                    if (null !== $this->objects->findByCode($sku, ObjectKind::Product, $tenant)) {
                        $skipped[] = $sku;

                        continue;
                    }

                    $variant = new CatalogObject($master->getObjectType(), $sku);
                    $variant->assignParent($master);
                    $this->em->persist($variant);

                    // 1. Direct-copy master ObjectValues (channel + locale preserved),
                    //    skipping any attribute that we are about to stamp as an axis.
                    //    Axis values must win over inherited master values.
                    foreach ($masterValues as $masterValue) {
                        $code = $masterValue->getAttribute()->getCode();
                        if (\array_key_exists($code, $axisAttributes)) {
                            continue;
                        }
                        $cloned = new ObjectValue(
                            object: $variant,
                            attribute: $masterValue->getAttribute(),
                            value: $masterValue->getValue(),
                            provenance: Provenance::Manual,
                        );
                        $cloned->changeChannelId($masterValue->getChannelId());
                        $cloned->changeLocale($masterValue->getLocale());
                        $this->em->persist($cloned);
                    }

                    // 2. Stamp axis values (color=red, size=S). Variant is fresh,
                    //    so no findOneByScope round-trip is needed — just persist.
                    foreach ($combination as $axisCode => $axisValue) {
                        $axisAttribute = $axisAttributes[$axisCode];
                        $axisOV = new ObjectValue(
                            object: $variant,
                            attribute: $axisAttribute,
                            // ADR-0019: select axes stamp the canonical
                            // {option_code} envelope, not a blind {value}.
                            value: AttributeType::Select === $axisAttribute->getType()
                                ? ['option_code' => $axisValue]
                                : ['value' => $axisValue],
                            provenance: Provenance::Manual,
                        );
                        $this->em->persist($axisOV);
                    }

                    $createdVariants[] = $variant;
                    $created[] = ['sku' => $sku, 'axis_values' => $combination];
                }

                // Persist the canonical axes definition on the master too —
                // the master is already managed by the EM (loaded above), so a
                // mutator + the upcoming flush is enough.
                $axesDefinition = [];
                foreach ($axes as $axisCode => $values) {
                    $axesDefinition[] = [
                        'code' => $axisCode,
                        'values' => $values,
                    ];
                }
                $master->declareVariantAxes($axesDefinition);

                // Single batched flush for every variant + master mutation.
                $this->em->flush();
            });
        } finally {
            $this->bulkContext->setBulk(false);
        }

        // BulkContext muted the sync listener during the bulk flush, so the
        // denormalised `attributes_indexed` cache is empty on the new
        // variants. Rebuild it synchronously here — the read path (product
        // detail + variants list) serves from this cache. Done after the
        // bulk transaction so a rebuild failure cannot orphan variants.
        foreach ($createdVariants as $variant) {
            $this->rebuilder->rebuild($variant);
        }
        if ([] !== $createdVariants) {
            $this->em->flush();
        }

        return new JsonResponse([
            'master_id' => $master->getId()->toRfc4122(),
            'created_count' => \count($created),
            'skipped_count' => \count($skipped),
            'created' => $created,
            'skipped_existing' => $skipped,
        ], Response::HTTP_CREATED);
    }

    /**
     * @param array<string, list<scalar>> $axes
     *
     * @return list<array<string, scalar>> ordered combinations
     */
    private function cartesianProduct(array $axes): array
    {
        $result = [[]];
        foreach ($axes as $axisCode => $values) {
            $next = [];
            foreach ($result as $combination) {
                foreach ($values as $value) {
                    $next[] = $combination + [$axisCode => $value];
                }
            }
            $result = $next;
        }

        /* @var list<array<string, scalar>> $result */
        return $result;
    }

    /**
     * @param array<string, scalar> $combination
     */
    private function renderSku(string $masterSku, array $combination, ?string $template): string
    {
        if (null === $template) {
            $parts = array_map(
                fn ($v): string => strtoupper($this->slugifyAxisValue((string) $v)),
                array_values($combination),
            );

            return $masterSku.'-'.implode('-', $parts);
        }

        $rendered = str_replace('{master_sku}', $masterSku, $template);
        foreach ($combination as $axis => $value) {
            $rendered = str_replace('{'.$axis.'}', $this->slugifyAxisValue((string) $value), $rendered);
        }

        return $rendered;
    }

    /**
     * Strip diacritics from an axis value before stamping it into a SKU.
     * Polish characters are mapped 1:1; anything else falls back to iconv
     * `ASCII//TRANSLIT` and a final regex purge of leftover non-ASCII to
     * keep SKUs URL-safe and grep-friendly. Empty / unmappable values
     * collapse to an empty string — the caller's `-` joiner still keeps
     * the SKU shape parseable.
     */
    private function slugifyAxisValue(string $value): string
    {
        $polish = [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
            'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N',
            'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z',
        ];
        $mapped = strtr($value, $polish);

        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $mapped);
        if (false === $transliterated) {
            $transliterated = $mapped;
        }

        return (string) preg_replace('/[^A-Za-z0-9_-]/', '', $transliterated);
    }
}
