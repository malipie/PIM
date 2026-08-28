<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Controller;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeOption;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\AttributeOptionRepositoryInterface;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeAttributeRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectValueRepositoryInterface;
use App\Catalog\Domain\Service\EffectiveAttributeGroupResolver;
use App\Channel\Contracts\ChannelResolverInterface;
use App\Channel\Contracts\LocaleFallbackResolverInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Shared\Application\ActiveLocaleResolverInterface;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Audit\CursorCodec;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * UI-02.5 (#295) — three lightweight read endpoints used by the
 * products list (UI-02.10 status indicators) and detail view
 * (UI-02.16 sticky header + right sidebar).
 *
 * - `GET /api/products/{id}/audit-log?limit=20` — last N changes from
 *   the DH Auditor `objects_audit` table. `diffs` JSON column is
 *   returned verbatim — the UI-02.13 `<AuditLogModal>` reads it.
 * - `GET /api/products/{id}/channels-status` — per-channel sync
 *   aggregate. MVP returns an empty list (no channel sync infra
 *   yet — Faza 1 publish flow populates it). The endpoint exists so
 *   the frontend can read the contract today and Faza 1 is a pure
 *   data-side switch.
 * - `GET /api/products/{id}/effective-attribute-groups` — proxy over
 *   `EffectiveAttributeGroupResolver` (UI-08.4) for product-context
 *   form rendering (UI-02.17 dynamic form).
 *
 * All three are tenant-gated through the existing TenantFilter on
 * `CatalogObject` reads — the resolver + DBAL queries inherit it.
 */
final class ProductReadEndpointsController
{
    private const string UUID_REGEX = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    private const int AUDIT_DEFAULT_LIMIT = 20;
    private const int AUDIT_MAX_LIMIT = 200;

    /**
     * Sentinel UUID returned for the synthetic "default" group that
     * carries ObjectType-attached attributes which are not declared in
     * any AttributeGroup. The all-zero UUID is recognised by the
     * frontend (`EffectiveModelCard`) as the bucket for ungrouped
     * fields and hidden from the "Effective model" sidebar listing —
     * see VIEW-07.1 (#421+).
     */
    public const string SYNTHETIC_DEFAULT_GROUP_ID = '00000000-0000-0000-0000-000000000000';

    public const string SYNTHETIC_DEFAULT_GROUP_CODE = 'default';

    public function __construct(
        private readonly CatalogObjectRepositoryInterface $objects,
        private readonly EffectiveAttributeGroupResolver $resolver,
        private readonly Connection $connection,
        private readonly ObjectTypeAttributeRepositoryInterface $objectTypeAttributes,
        private readonly AttributeOptionRepositoryInterface $attributeOptions,
        private readonly ObjectValueRepositoryInterface $objectValues,
        private readonly LocaleFallbackResolverInterface $localeFallback,
        private readonly ChannelResolverInterface $channels,
        private readonly ActiveLocaleResolverInterface $activeLocales,
    ) {
    }

    #[Route(
        '/api/products/{id}/audit-log',
        name: 'pim_products_audit_log',
        requirements: ['id' => self::UUID_REGEX],
        methods: ['GET'],
        priority: 200,
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'products', action: 'view')]
    public function auditLog(string $id, Request $request): JsonResponse
    {
        $product = $this->mustFindProduct($id);

        $rawLimit = $request->query->getInt('limit', self::AUDIT_DEFAULT_LIMIT);
        $limit = max(1, min(self::AUDIT_MAX_LIMIT, $rawLimit));

        // HARD-05 — opaque cursor over (created_at, id). Without it the
        // UI is stuck on the first page and operators cannot reach
        // older history once a product accumulates >LIMIT entries.
        $cursor = CursorCodec::decode($request->query->get('cursor'));

        $sql = 'SELECT id, type, blame_user, diffs, created_at
                FROM objects_audit
                WHERE object_id = :objectId';
        $params = ['objectId' => $product->getId()->toRfc4122()];
        $types = ['limit' => ParameterType::INTEGER];

        if (null !== $cursor) {
            // Tuple comparison: rows older than the cursor in
            // (created_at, id) lexicographic order. Because id is
            // monotonically increasing across the audit table, the
            // comparison stays total even when timestamps tie.
            $sql .= ' AND (created_at, id) < (:cursorT, :cursorI)';
            $params['cursorT'] = $cursor['t'];
            $params['cursorI'] = $cursor['i'];
            $types['cursorI'] = ParameterType::INTEGER;
        }

        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT :limit';
        $params['limit'] = $limit;

        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);

        $entries = [];
        foreach ($rows as $row) {
            $diffs = $row['diffs'] ?? null;
            $entries[] = [
                'type' => $row['type'] ?? null,
                'user' => $row['blame_user'] ?? null,
                'diffs' => \is_string($diffs) ? json_decode($diffs, true) : null,
                'created_at' => $row['created_at'] ?? null,
            ];
        }

        // Next cursor only when the page is full — a partial page means
        // we have served the tail and there is nothing more to fetch.
        $nextCursor = null;
        if (\count($rows) === $limit) {
            $last = $rows[\count($rows) - 1];
            $lastCreatedAt = $last['created_at'] ?? null;
            $lastId = $last['id'] ?? null;
            if (\is_string($lastCreatedAt) && (\is_int($lastId) || (\is_string($lastId) && ctype_digit($lastId)))) {
                $nextCursor = CursorCodec::encode($lastCreatedAt, (int) $lastId);
            }
        }

        return new JsonResponse([
            'product_id' => $product->getId()->toRfc4122(),
            'entries' => $entries,
            'next_cursor' => $nextCursor,
        ]);
    }

    #[Route(
        '/api/products/{id}/channels-status',
        name: 'pim_products_channels_status',
        requirements: ['id' => self::UUID_REGEX],
        methods: ['GET'],
        priority: 200,
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'products', action: 'view')]
    public function channelsStatus(string $id): JsonResponse
    {
        $product = $this->mustFindProduct($id);

        // Per-channel sync rows ship with the Faza 1 publish flow; the
        // MVP aggregate column on `objects` lives in UI-02.1 but the
        // per-channel breakdown table is not in the schema yet. Until
        // it is, the endpoint exists with a stable shape so the
        // frontend (UI-02.10 / UI-02.16 right sidebar) can read it.
        return new JsonResponse([
            'product_id' => $product->getId()->toRfc4122(),
            'aggregate' => $product->getSyncStatusAggregate(),
            'channels' => [],
        ]);
    }

    #[Route(
        '/api/products/{id}/effective-attribute-groups',
        name: 'pim_products_effective_attribute_groups',
        requirements: ['id' => self::UUID_REGEX],
        methods: ['GET'],
        priority: 200,
    )]
    // UP-07 (#1023) — poly-kind mirror so /objects/:slug/:id detail view
    // can resolve effective groups (overlay-by-category) for any
    // ObjectType, not just kind=product. mustFindObject relaxes the kind
    // gate; the resolver itself is kind-agnostic (operates on
    // CatalogObject + ObjectType).
    #[Route(
        '/api/objects/{id}/effective-attribute-groups',
        name: 'pim_objects_effective_attribute_groups',
        requirements: ['id' => self::UUID_REGEX],
        methods: ['GET'],
        priority: 200,
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'products', action: 'view')]
    public function effectiveAttributeGroups(string $id): JsonResponse
    {
        $product = $this->mustFindObject($id);

        $groups = $this->resolver->resolve($product);
        $byGroup = $this->resolver->loadGroupAttributes($groups);
        $displayModes = $this->resolver->loadObjectTypeGroupDisplayModes($product->getObjectType());

        // Pre-pass: collect every select / multiselect Attribute reachable
        // from this product so we can ship its `AttributeOption` list in the
        // same response. Without this the detail page renders a free-text
        // input for predefined-value attributes and operators have to type
        // option codes by hand instead of picking them.
        /** @var array<string, Attribute> $optionsAttrs */
        $optionsAttrs = [];
        foreach ($groups as $group) {
            foreach ($byGroup[$group->getId()->toRfc4122()] ?? [] as $j) {
                $a = $j->getAttribute();
                if ($a->getType()->usesOptions()) {
                    $optionsAttrs[$a->getId()->toRfc4122()] = $a;
                }
            }
        }
        foreach ($this->objectTypeAttributes->findByObjectType($product->getObjectType()) as $junction) {
            $a = $junction->getAttribute();
            if ($a->getType()->usesOptions()) {
                $optionsAttrs[$a->getId()->toRfc4122()] = $a;
            }
        }
        $optionsByAttributeId = $this->loadOptionsByAttributeId(array_values($optionsAttrs));

        // Track every Attribute UUID already exposed via a real group so
        // the synthetic "default" bucket below never duplicates them.
        $seenAttributeIds = [];

        $effective = [];
        foreach ($groups as $position => $group) {
            $junctions = $byGroup[$group->getId()->toRfc4122()] ?? [];
            $attributes = [];
            foreach ($junctions as $j) {
                $attribute = $j->getAttribute();
                $seenAttributeIds[$attribute->getId()->toRfc4122()] = true;
                $attributes[] = $this->serializeAttribute(
                    $attribute,
                    $j->getPosition(),
                    $j->isRequiredInGroup(),
                    $j->getVisibleWhen(),
                    $optionsByAttributeId,
                );
            }
            $effective[] = [
                'id' => $group->getId()->toRfc4122(),
                'code' => $group->getCode(),
                'label' => $group->getLabel(),
                'icon' => $group->getIcon(),
                'color' => $group->getColor(),
                'is_system_group' => $group->isSystemGroup(),
                'position' => $position,
                'display_mode' => $displayModes[$group->getId()->toRfc4122()] ?? 'tab',
                'attributes' => $attributes,
            ];
        }

        // VIEW-07.1 (#421+) — surface ObjectType-attached attributes
        // that are not declared in any AttributeGroup. Without this the
        // detail page would hide loose ObjectType attributes whenever the
        // ObjectType has no explicit AttributeGroup memberships. The
        // synthetic group is appended last so curated
        // grouping always wins the sort order; an all-zero UUID acts as
        // a sentinel for the frontend to recognise + hide it from the
        // "Effective model" sidebar listing.
        $junctions = $this->objectTypeAttributes->findByObjectType($product->getObjectType());
        usort(
            $junctions,
            static fn ($a, $b): int => $a->getSortOrder() <=> $b->getSortOrder(),
        );
        $defaultAttributes = [];
        foreach ($junctions as $junction) {
            $attribute = $junction->getAttribute();
            $key = $attribute->getId()->toRfc4122();
            if (isset($seenAttributeIds[$key])) {
                continue;
            }
            $seenAttributeIds[$key] = true;
            $defaultAttributes[] = $this->serializeAttribute(
                $attribute,
                $junction->getSortOrder(),
                $junction->isRequiredForCompleteness(),
                null,
                $optionsByAttributeId,
            );
        }

        if ([] !== $defaultAttributes) {
            $effective[] = [
                'id' => self::SYNTHETIC_DEFAULT_GROUP_ID,
                'code' => self::SYNTHETIC_DEFAULT_GROUP_CODE,
                'label' => ['pl' => 'Atrybuty', 'en' => 'Attributes'],
                'icon' => null,
                'color' => null,
                'is_system_group' => false,
                'is_synthetic' => true,
                'position' => \count($effective),
                'display_mode' => 'stacked',
                'attributes' => $defaultAttributes,
            ];
        }

        return new JsonResponse([
            'product_id' => $product->getId()->toRfc4122(),
            'groups' => $effective,
            // #1149 — the locale picker is fed from here (a `products.view`
            // endpoint the detail page already calls) rather than the
            // permission-gated /api/tenant-locales, so an operator without
            // settings access still gets the tenant's real locales.
            'locales' => $this->serializeTenantLocales($product),
        ]);
    }

    /**
     * @return list<array{code: string, is_default: bool}>
     */
    private function serializeTenantLocales(CatalogObject $product): array
    {
        $tenant = $product->getTenant();
        if (!$tenant instanceof Tenant) {
            return [];
        }

        // #1352 (reopen #2) — ACTIVE tenant_locales are the single source
        // of truth; the legacy JSONB list is only a fallback for tenants
        // without LOC-07 rows (it kept ghost locales after deactivation).
        $languages = $this->activeLocales->languages($tenant);
        $primary = $this->activeLocales->primaryLanguage($tenant) ?? $tenant->getPrimaryLocale();
        if ([] === $languages) {
            $languages = $tenant->getEnabledLocales();
        }

        $locales = [];
        foreach ($languages as $code) {
            $locales[] = ['code' => $code, 'is_default' => $code === $primary];
        }
        // Primary first so the picker defaults to it deterministically.
        usort($locales, static fn (array $a, array $b): int => ($b['is_default'] ? 1 : 0) <=> ($a['is_default'] ? 1 : 0));

        return $locales;
    }

    /**
     * #1222 — returns per-attribute scope status for a requested locale/channel.
     *
     * Response shape:
     *   { "attribute_code": { "has_override": bool, "inherited_from": string|null } }
     *
     * - `has_override=true`  → an ObjectValue row exists for the exact requested locale/channel.
     * - `has_override=false, inherited_from="en"` → value shown comes from a fallback locale.
     * - `has_override=false, inherited_from=null`  → global value is used (primary locale or no locale scope).
     */
    #[Route(
        '/api/products/{id}/scope-status',
        name: 'pim_products_scope_status',
        requirements: ['id' => self::UUID_REGEX],
        methods: ['GET'],
        priority: 200,
    )]
    #[Route(
        '/api/objects/{id}/scope-status',
        name: 'pim_objects_scope_status',
        requirements: ['id' => self::UUID_REGEX],
        methods: ['GET'],
        priority: 200,
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'products', action: 'view')]
    public function scopeStatus(string $id, Request $request): JsonResponse
    {
        $object = $this->mustFindObject($id);
        $tenant = $object->getTenant();
        if (!$tenant instanceof Tenant) {
            return new JsonResponse([]);
        }

        $requestedLocale = $request->query->get('locale');
        $requestedChannel = $request->query->get('channel');

        // Primary locale is stored as global (null) — treat as no locale scope.
        $effectiveLocale = null !== $requestedLocale
            && $requestedLocale !== $tenant->getPrimaryLocale()
            ? $requestedLocale
            : null;

        $channelId = null;
        if (null !== $requestedChannel) {
            $channelId = $this->channels->resolveId($requestedChannel, $tenant);
        }

        // Build locale fallback chain for inheritance detection.
        $localeChain = null !== $effectiveLocale
            ? $this->localeFallback->resolve($effectiveLocale, $tenant)
            : [];

        // Index: locale code → chain position (0 = most specific).
        $localeRankMap = [];
        foreach ($localeChain as $pos => $code) {
            $localeRankMap[$code] = $pos;
        }

        // Load all ObjectValue rows for this object.
        $allValues = $this->objectValues->findByObject($object);

        // Per attribute: find the best row and determine if it is exact or inherited.
        $maxChainLen = \count($localeChain);
        /** @var array<string, array{rank: int, locale: ?string}> $best */
        $best = [];
        foreach ($allValues as $value) {
            $valueLocale = $value->getLocale();
            $valueChannel = $value->getChannelId();

            if (null !== $valueLocale) {
                if (!isset($localeRankMap[$valueLocale])) {
                    continue;
                }
                $localeRankContrib = ($maxChainLen - $localeRankMap[$valueLocale]) * 2;
            } else {
                $localeRankContrib = 0;
            }

            if (null !== $valueChannel) {
                if (null === $channelId || !$valueChannel->equals($channelId)) {
                    continue;
                }
                $channelRankContrib = 1;
            } else {
                $channelRankContrib = 0;
            }

            $rank = $localeRankContrib + $channelRankContrib;
            if (0 === $rank) {
                continue; // global row — not a scoped override
            }

            $code = $value->getAttribute()->getCode();
            if (!isset($best[$code]) || $rank > $best[$code]['rank']) {
                $best[$code] = ['rank' => $rank, 'locale' => $valueLocale];
            }
        }

        // Build response: for each attribute with a scoped value, report
        // whether it is an exact match or inherited from a fallback locale.
        $result = [];
        foreach ($best as $attrCode => $entry) {
            $isExact = $entry['locale'] === $effectiveLocale;
            $result[$attrCode] = [
                'has_override' => $isExact,
                'inherited_from' => $isExact ? null : $entry['locale'],
            ];
        }

        return new JsonResponse($result);
    }

    private function mustFindProduct(string $id): CatalogObject
    {
        $product = $this->objects->findById(Uuid::fromString($id));
        if (!$product instanceof CatalogObject || ObjectKind::Product !== $product->getKind()) {
            throw new NotFoundHttpException(\sprintf('Product %s not found.', $id));
        }

        return $product;
    }

    /**
     * UP-07 (#1023) — poly-kind lookup for the universal
     * `/api/objects/{id}/effective-attribute-groups` route. No kind
     * gate; tenant + voter scoping happens upstream via
     * `CatalogObjectRepository`.
     */
    private function mustFindObject(string $id): CatalogObject
    {
        $object = $this->objects->findById(Uuid::fromString($id));
        if (!$object instanceof CatalogObject) {
            throw new NotFoundHttpException(\sprintf('Object %s not found.', $id));
        }

        return $object;
    }

    /**
     * @param list<Attribute> $attributes
     *
     * @return array<string, list<array{code: string, label: array<string, string>, color: ?string, is_default: bool, is_deprecated: bool}>>
     */
    private function loadOptionsByAttributeId(array $attributes): array
    {
        $byId = [];
        foreach ($this->attributeOptions->findByAttributes($attributes) as $option) {
            $byId[$option->getAttribute()->getId()->toRfc4122()][] = [
                'code' => $option->getCode(),
                'label' => $option->getLabel(),
                'color' => $option->getColor(),
                'is_default' => $option->isDefault(),
                'is_deprecated' => $option->isDeprecated(),
            ];
        }

        return $byId;
    }

    /**
     * @param array<string, list<array{code: string, label: array<string, string>, color: ?string, is_default: bool, is_deprecated: bool}>> $optionsByAttributeId
     *
     * @return array<string, mixed>
     */
    private function serializeAttribute(
        Attribute $attribute,
        int $position,
        bool $isRequiredInGroup,
        mixed $visibleWhen,
        array $optionsByAttributeId,
    ): array {
        $payload = [
            'id' => $attribute->getId()->toRfc4122(),
            'code' => $attribute->getCode(),
            'type' => $attribute->getType()->value,
            'label' => $attribute->getLabel(),
            'is_system' => $attribute->isSystem(),
            // #1151 — drives the per-locale value flow on the detail page:
            // the AttrRow chip + per-locale read/write switch on this flag
            // instead of the old code-suffix heuristic. is_scopable pairs
            // it for the channels epic (#1147).
            'is_localizable' => $attribute->isLocalizable(),
            'is_scopable' => $attribute->isScopable(),
            'position' => $position,
            'is_required_in_group' => $isRequiredInGroup,
            // #1350 — the global "Wymagany" flag from the attribute editor.
            // Distinct from is_required_in_group (group-local/completeness):
            // this one hard-blocks saving an empty value (FE + upserter).
            'is_required' => $attribute->isRequired(),
            'visible_when' => $visibleWhen,
        ];

        // Only ship `options` for select-like types — other types ignore
        // the field, and we want the payload to stay tight.
        if ($attribute->getType()->usesOptions()) {
            $payload['options'] = $optionsByAttributeId[$attribute->getId()->toRfc4122()] ?? [];
        }

        // MODR-05 (#927) — ship the relation target ObjectType codes so the
        // detail page can render the link icon + a "links to: X, Y" tooltip
        // without a follow-up fetch. The codes already live on the
        // Attribute entity; the FE resolves them to display labels via the
        // ObjectType cache (codes are stable identifiers in MVP).
        if (AttributeType::Relation === $attribute->getType()) {
            $payload['relation_target_object_type_ids'] = $attribute->getRelationTargetObjectTypeIds();
            $payload['relation_cardinality'] = $attribute->getRelationCardinality()?->value;
            // MODR-08 (#930) — list of target attribute codes the rich
            // preview card surfaces. Empty list = default card layout.
            $payload['relation_preview_fields'] = $attribute->getRelationPreviewFields();
        }

        return $payload;
    }
}
