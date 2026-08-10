<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Identity\Contracts\Policy\AttributePermissionReader;
use App\Shared\Domain\Tenant;

/**
 * AUD-008 (#1578) — read-side enforcement of the 3-state per-attribute
 * permissions (PRD §3.5) on the catalog item/collection GET providers.
 *
 * `attributesIndexed` is keyed by attribute code, but the policy contract
 * ({@see AttributePermissionReader}) answers per attribute UUID. We resolve
 * the tenant's attribute catalogue once (one query via
 * {@see AttributeRepositoryInterface::findAllByTenant()}), map code → id,
 * and drop every key whose resolved permission is `Restricted`
 * (`canViewAttribute` === false) for the current user.
 *
 * Codes with no matching Attribute row — the system attributes injected by
 * {@see SystemAttributeReadOverlay} (`created_at`, `updated_at`, `created_by`,
 * `updated_by`) and any stale/removed code — are NOT attribute-permission
 * subjects, so they pass through untouched. Run this overlay AFTER the
 * system overlay so those keys are present and preserved.
 *
 * Mutation happens on a clone through the side-effect-free
 * {@see CatalogObject::overlayAttributesIndexedForRead()} — same
 * non-persisting contract the locale/channel and system overlays rely on,
 * so it is safe inside a GET provider.
 *
 * Perf (#1620): the collection providers call {@see applyBatch()} so the
 * tenant attribute catalogue ({@see AttributeRepositoryInterface::findAllByTenant()})
 * is resolved ONCE for the whole page and each attribute's view decision is
 * memoised across items — instead of one `findAllByTenant` + one
 * `canViewAttribute` per attribute *per item* (N+1 → 10s timeout on
 * `?itemsPerPage=200`). The memo is a local variable scoped to a single
 * call, so nothing leaks between requests under FrankenPHP worker mode —
 * the service stays `readonly` and stateless.
 *
 * Perf (#2794): memoising was not enough — the FIRST resolve of each id
 * still cost its own round-trips, so a 32-attribute catalogue meant a
 * constant ~128 queries on every `GET /api/products` no matter how many
 * items the page carried (GOLIVE #2234). The overlay now asks
 * {@see AttributePermissionReader::canViewAttributes()} once per tenant
 * with the whole catalogue, turning the page's permission cost into a
 * fixed pair of set-based SELECTs.
 */
final readonly class AttributeReadRestrictionOverlay
{
    public function __construct(
        private AttributeRepositoryInterface $attributes,
        private AttributePermissionReader $permissions,
    ) {
    }

    public function apply(CatalogObject $object): CatalogObject
    {
        $idByCodeByTenant = [];
        $canView = [];

        return $this->applyOne($object, $idByCodeByTenant, $canView);
    }

    /**
     * Batch variant for the collection providers. Resolves the per-tenant
     * `code => id` catalogue once per distinct tenant in the page and reuses
     * one `canViewAttribute` decision per attribute id across every item.
     *
     * @param list<CatalogObject> $objects
     *
     * @return list<CatalogObject>
     */
    public function applyBatch(array $objects): array
    {
        // Shared within this call only — keyed by tenant id for the (rare)
        // mixed-tenant page, never persisted on the (shared) service.
        $idByCodeByTenant = [];
        // attribute id (RFC4122) => can the current principal view it.
        $canView = [];

        $out = [];
        foreach ($objects as $object) {
            $out[] = $this->applyOne($object, $idByCodeByTenant, $canView);
        }

        return $out;
    }

    /**
     * @param array<string, array<string, \Symfony\Component\Uid\Uuid>> $idByCodeByTenant tenant id => (code => id)
     * @param array<string, bool>                                       $canView          attribute id => view decision
     */
    private function applyOne(CatalogObject $object, array &$idByCodeByTenant, array &$canView): CatalogObject
    {
        $tenant = $object->getTenant();
        if (!$tenant instanceof Tenant) {
            return $object;
        }

        $indexed = $object->getAttributesIndexed();
        if ([] === $indexed) {
            return $object;
        }

        $tenantId = $tenant->getId()->toRfc4122();
        $idByCode = $idByCodeByTenant[$tenantId] ??= $this->idByCode($tenant);

        // #2794 — resolve the tenant's whole attribute catalogue in ONE
        // call the first time we touch it. Asking per attribute cost two
        // SELECTs per id even with the policy's own cache, which is what
        // made the constant per-request query count of GOLIVE #2234.
        $unresolved = [];
        foreach ($idByCode as $attributeId) {
            if (!\array_key_exists($attributeId->toRfc4122(), $canView)) {
                $unresolved[] = $attributeId;
            }
        }
        if ([] !== $unresolved) {
            $canView += $this->permissions->canViewAttributes($unresolved);
        }

        $filtered = [];
        foreach ($indexed as $code => $value) {
            $attributeId = $idByCode[$code] ?? null;
            // Unknown code = not an attribute-permission subject (system
            // attribute / stale profile) → keep it.
            if (null === $attributeId) {
                $filtered[$code] = $value;

                continue;
            }

            $visible = $canView[$attributeId->toRfc4122()] ?? false;
            if ($visible) {
                $filtered[$code] = $value;
            }
        }

        if (\count($filtered) === \count($indexed)) {
            // Nothing dropped — avoid the clone + index overlay entirely.
            return $object;
        }

        $copy = clone $object;
        $copy->overlayAttributesIndexedForRead($filtered);

        return $copy;
    }

    /**
     * @return array<string, \Symfony\Component\Uid\Uuid> attribute code => id
     */
    private function idByCode(Tenant $tenant): array
    {
        $map = [];
        foreach ($this->attributes->findAllByTenant($tenant) as $attribute) {
            $map[$attribute->getCode()] = $attribute->getId();
        }

        return $map;
    }
}
