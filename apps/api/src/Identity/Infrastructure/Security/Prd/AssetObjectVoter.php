<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security\Prd;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\ObjectKind;
use App\Identity\Infrastructure\Security\AbstractPrdVoter;

/**
 * #2845 — PRD §3.2 authorization for `/api/assets`, i.e. assets as
 * **catalog objects** (`CatalogObject` with `kind=asset`).
 *
 * There are two different things called "asset" in this codebase and they
 * need two voters:
 *
 *   - `/api/asset_storage` serves the {@see \App\Asset\Domain\Entity\Asset}
 *     entity — the stored binary, its checksum and storage path. Covered by
 *     the sibling {@see AssetVoter}.
 *   - `/api/assets` serves `CatalogObject(kind=asset)` — the catalogued
 *     media item with attribute values, completeness and category links,
 *     which is what the admin's Multimedia section lists. That is this
 *     voter, and until now **no PRD voter answered for it at all**, so every
 *     PRD role got a 403 on the Multimedia list.
 *
 * Both surfaces map onto the same `multimedia.*` codes: from the role's
 * point of view it is one permission ("may see multimedia"), even though
 * the API models the binary and its catalogue entry separately.
 *
 * The collection and POST carry the bare CatalogObject class string, shared
 * with `/categories` and the poly-kind `/objects`, so they get their own
 * `READ_ASSET` attribute rather than an alias on `READ` — same reasoning as
 * {@see \App\Identity\Infrastructure\Security\ProductVoter} (#2416) and
 * {@see CategoryVoter}.
 */
final class AssetObjectVoter extends AbstractPrdVoter
{
    /**
     * @return array<string, string|list<string>>
     */
    protected function permissionMap(): array
    {
        return [
            'READ_ASSET' => 'multimedia.view',

            // Instance-carrying operations: acceptsSubject() checks the kind,
            // supports() keeps this voter out of class-string decisions.
            'READ' => 'multimedia.view',
            'UPDATE' => ['multimedia.add_edit_any', 'multimedia.add_edit_own'],
            'DELETE' => 'multimedia.delete',
        ];
    }

    /**
     * Class-string subjects are shared by every kind, so the kind cannot be
     * checked — those decisions belong to READ_ASSET.
     */
    protected function supports(string $attribute, mixed $subject): bool
    {
        if (\is_string($subject) && \in_array($attribute, ['READ', 'UPDATE', 'DELETE'], true)) {
            return false;
        }

        return parent::supports($attribute, $subject);
    }

    protected function subjectClass(): string
    {
        return CatalogObject::class;
    }

    protected function acceptsSubject(mixed $subject): bool
    {
        if (\is_string($subject)) {
            return CatalogObject::class === $subject;
        }

        if (!$subject instanceof CatalogObject) {
            return false;
        }

        return ObjectKind::Asset === $subject->getKind();
    }
}
