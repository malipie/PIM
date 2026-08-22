<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Service;

use App\Catalog\Contracts\Query\AttributeSummary;
use Symfony\Component\Uid\Uuid;

/**
 * RBAC-P5-007 (#697) — cross-BC read API the Identity bundle uses to
 * paint its attribute-permission tab.
 *
 * Identity owns the "which role overrides which attribute" mapping
 * (`role_attribute_permissions` table) but does NOT own attribute
 * metadata; Catalog does. Rather than coupling Identity to
 * `App\Catalog\Domain\Repository\AttributeRepositoryInterface`
 * (which would break the Identity_Internals → Catalog_Internals
 * deptrac fence), Identity depends on this contracts-layer reader
 * and gets back lean {@see AttributeSummary} DTOs.
 */
interface AttributeCatalogReader
{
    /**
     * @return list<AttributeSummary> ordered by group position, then
     *                                attribute code, so the role
     *                                editor renders deterministically
     */
    public function findAllByTenant(Uuid $tenantId): array;

    /**
     * Validates that an attribute exists on the given tenant. Returns
     * the summary on hit, null on miss (unknown id OR cross-tenant id).
     */
    public function findOnTenant(Uuid $attributeId, Uuid $tenantId): ?AttributeSummary;

    /**
     * #2946 — option codes + labels for a select/multiselect attribute,
     * ordered by position.
     *
     * The agent needs them to translate what an operator says ("Dla
     * dziewczyn") into what a write accepts (the option code). Without it
     * the only honest answer to "change gender to Dla dziewczyn" is to ask
     * the operator to go and read the code out of the modeling screen.
     *
     * Empty for a non-option attribute or an unknown/cross-tenant id — the
     * caller reads that as "nothing to choose from".
     *
     * @return list<array{code: string, label: array<string, string>}>
     */
    public function optionsFor(Uuid $attributeId, Uuid $tenantId): array;
}
