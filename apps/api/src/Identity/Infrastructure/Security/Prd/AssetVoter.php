<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security\Prd;

use App\Asset\Domain\Entity\Asset;
use App\Identity\Infrastructure\Security\AbstractPrdVoter;

/**
 * RBAC-P3-003 (#666) — per-asset authorization aligned with the
 * PRD §3.2 macierz permission codes for the multimedia row
 * (`multimedia.view`, `multimedia.add_edit_own`,
 * `multimedia.add_edit_any`, `multimedia.delete`).
 *
 * Subject: per ADR-009 Asset carries storage details on its own table
 * while user-defined metadata lives on a paired `CatalogObject(kind=asset)`
 * — see the Asset entity PHPDoc. This voter handles the storage entity
 * directly because all asset CRUD endpoints route through it.
 *
 * Scope split per the Phase 3 ticket plan:
 *   - this voter handles the **broad PRD §3.2 gate** only;
 *   - the own-vs-any ownership semantics land in
 *     {@see \App\Identity\Application\Policy\OwnershipPolicy} (RBAC-P3-010,
 *     #673) — the resource currently has no `uploaded_by` column, so the
 *     ownership policy is introduced together with the schema change in
 *     that ticket. Until then the voter affirms whichever of
 *     `multimedia.add_edit_own` / `multimedia.add_edit_any` the caller's
 *     role carries; the ownership check stacks on top once #673 lands.
 *
 * The legacy {@see \App\Identity\Infrastructure\Security\AssetVoter}
 * still covers the uppercase `READ`/`WRITE`/`DELETE` attribute style used
 * by Asset.xml + Asset controllers; both run side-by-side until the
 * Phase 6 retrofit migrates the surface over.
 */
final class AssetVoter extends AbstractPrdVoter
{
    /**
     * @return array<string, string|list<string>>
     */
    protected function permissionMap(): array
    {
        return [
            'view' => 'multimedia.view',
            'add_edit_own' => 'multimedia.add_edit_own',
            'add_edit_any' => 'multimedia.add_edit_any',
            'delete' => 'multimedia.delete',

            // #2845 — a plain `READ` alias IS safe here, unlike on the
            // CatalogObject voters. #2838 withheld it on the strength of a
            // comment claiming this voter answers for `CatalogObject`; it
            // does not — subjectClass() below is the storage `Asset` entity,
            // which only `/api/asset_storage` ever passes. There is no kind
            // discriminator to be blind to, so the class-string subject is
            // already unambiguous.
            'READ' => 'multimedia.view',

            // #2881 follow-up — the upload, patch and delete controllers do
            // not stop at their #[RequiresPermission]: they re-check
            // `isGranted('CREATE'|'UPDATE'|'DELETE', Asset)` in the method
            // body. That second gate answered only to the legacy grid, so
            // upload returned "Forbidden" on production to a role holding
            // every multimedia code — the endpoint attribute let it in and
            // the inline check threw it out. Same codes as the attribute,
            // for the same reason (`assets` has no uploader column, so
            // `_own` and `_any` are indistinguishable).
            'CREATE' => ['multimedia.add_edit_own', 'multimedia.add_edit_any'],
            'UPDATE' => ['multimedia.add_edit_own', 'multimedia.add_edit_any'],
            'DELETE' => 'multimedia.delete',
        ];
    }

    protected function subjectClass(): string
    {
        return Asset::class;
    }
}
