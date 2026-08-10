<?php

declare(strict_types=1);

namespace App\Identity\Application\Serializer;

use App\Identity\Application\Policy\AttributePermissionPolicy;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Rbac\AttributePermission;
use Symfony\Component\Uid\Uuid;

/**
 * RBAC-P3-012 (#675) — composes {@see AttributePermissionPolicy} with the
 * `integration_visible` flag to produce the per-attribute response shape
 * defined in PRD §3.5.
 *
 * Input  — map of `attributeId (UUID string)` → raw value (already
 *          serialised by upstream normaliser).
 * Output — map of `attributeId (UUID string)` → {@see RestrictedField}.
 *          Keys whose resolved permission is `Restricted` are removed
 *          entirely; PRD §3.5: *„`'restricted'` → field NIE w response
 *          (full removal)"*.
 *
 * `integration_visible` composition (PRD §3.5):
 *
 *   - default flag value on Attribute is `true` — no effect.
 *   - flag `false` for a given attribute → caller sees the field as
 *     `view-only` regardless of per-role grant; the
 *     `RestrictedField::reason` is set to `integration_visible` so the
 *     frontend can surface the right tooltip.
 *   - `integration_visible=false` does NOT promote `restricted` to
 *     visible; if the policy already says `restricted`, the field is
 *     still removed.
 *
 * Wiring per endpoint (CatalogObject serializer hook, ApiToken response
 * normaliser, audit log filter) is the Phase 6 retrofit's
 * responsibility — this filter is the building block. Tests cover the
 * pure filter behaviour; integration tests come with the wiring.
 */
final readonly class FieldRestrictionFilter
{
    public function __construct(private AttributePermissionPolicy $policy)
    {
    }

    /**
     * @param array<string, mixed> $valuesByAttributeId           map `attributeId (uuid)` → raw value
     * @param array<string, bool>  $integrationVisibleByAttribute map `attributeId (uuid)` → integration_visible flag; missing keys default to true
     *
     * @return array<string, RestrictedField>
     */
    public function restrict(
        User $user,
        array $valuesByAttributeId,
        array $integrationVisibleByAttribute = [],
    ): array {
        // #2794 — one batch resolve for the whole field map. The previous
        // shape called the policy once per attribute, and each of those
        // calls issued four SELECTs, which made `GET /api/products` cost
        // ~128 queries per request regardless of page size (GOLIVE #2234).
        $attributeKeys = [];
        $attributeIds = [];
        foreach (array_keys($valuesByAttributeId) as $attributeIdString) {
            $attributeId = Uuid::fromString($attributeIdString);
            $attributeKeys[$attributeIdString] = $attributeId->toRfc4122();
            $attributeIds[] = $attributeId;
        }
        $permissions = $this->policy->resolvePermissions($user, $attributeIds);

        $out = [];
        foreach ($valuesByAttributeId as $attributeIdString => $value) {
            $permission = $permissions[$attributeKeys[$attributeIdString]] ?? AttributePermission::Restricted;

            if (AttributePermission::Restricted === $permission) {
                continue;
            }

            $visible = $integrationVisibleByAttribute[$attributeIdString] ?? true;
            if (!$visible) {
                // integration_visible=false demotes edit/view to view-only
                // with the dedicated reason — PRD §3.5 makes this flag
                // *independent* of per-role grant, so we apply it after
                // the policy resolved the role decision.
                $out[$attributeIdString] = RestrictedField::viewOnly(
                    $value,
                    RestrictedField::REASON_INTEGRATION_HIDDEN,
                );
                continue;
            }

            $out[$attributeIdString] = AttributePermission::Edit === $permission
                ? RestrictedField::editable($value)
                : RestrictedField::viewOnly($value);
        }

        return $out;
    }
}
