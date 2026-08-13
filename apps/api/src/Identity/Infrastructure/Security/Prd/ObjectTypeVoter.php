<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security\Prd;

use App\Catalog\Domain\Entity\ObjectType;
use App\Identity\Infrastructure\Security\AbstractPrdVoter;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * RBAC-P3-004 (#667) — per-ObjectType authorization aligned with the
 * PRD §3.2 Modeling row (`modeling.view`, `modeling.object_types.add`,
 * `modeling.delete_custom`).
 *
 * Mapping rationale:
 *   - `view`        → `modeling.view` (broad modeling read gate),
 *   - `add` / `edit` → `modeling.object_types.add` (the macierz collapses
 *     creation and structural edits onto the same Modeler-owned action;
 *     built-ins are protected separately below),
 *   - `delete`      → `modeling.delete_custom` **plus** an `is_built_in=false`
 *     runtime guard. The platform-owned built-ins (Product / Category /
 *     Asset / Brand) cannot be deleted by anyone — Tenant Owner included —
 *     because they back the API sugar paths. The 409 response shape comes
 *     from the controller; the voter denies before the controller runs.
 *
 * Sibling voters in this ticket — AttributeVoter, AttributeGroupVoter —
 * piggyback on the same PRD row but with simpler resolution (no
 * is_built_in check; the protected built-ins live on ObjectType only).
 *
 * Auto-grant of `{kind}.view` / `{kind}.edit` to roles flagged with
 * `modeling.auto_grant_new_object_types` lives in a Doctrine post-persist
 * listener — deferred to a focused follow-up ticket because it requires
 * a new dynamic-permission code namespace plus role_permissions writes
 * tied to schema operations.
 */
final class ObjectTypeVoter extends AbstractPrdVoter
{
    /**
     * #2838 — reading a type definition is implied by reading the data it
     * describes. Rendering a product list needs the product ObjectType;
     * requiring `modeling.view` for that would hand the Catalog Manager
     * the entire Modeling section (MENU_PERMISSIONS.modeling keys on
     * exactly this code), which the PRD §3.2 matrix denies it. Writes stay
     * on `modeling.*` — this widens reading, not modelling.
     */
    private const array METADATA_READERS = [
        'modeling.view',
        'products.view',
        'categories.view',
        'multimedia.view',
    ];

    /**
     * @return array<string, string|list<string>>
     */
    protected function permissionMap(): array
    {
        return [
            'view' => self::METADATA_READERS,
            'add' => 'modeling.object_types.add',
            'edit' => 'modeling.object_types.add',
            'delete' => 'modeling.delete_custom',

            // #2838 — API Platform asks in uppercase (`is_granted('READ',
            // ObjectType::class)`), this voter only spoke lowercase, so the
            // legacy voter was the only one voting and it demands
            // `object_type.read` — a code no PRD role holds. The product
            // list therefore 403'd on its own type definition and the UI
            // reported it as "run the catalog seeder". Same shape as the
            // fix in ProductVoter (#2416).
            'READ' => self::METADATA_READERS,
            'CREATE' => 'modeling.object_types.add',
            'UPDATE' => 'modeling.object_types.add',
            'WRITE' => 'modeling.object_types.add',
            'DELETE' => 'modeling.delete_custom',
        ];
    }

    protected function subjectClass(): string
    {
        return ObjectType::class;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        if (!parent::voteOnAttribute($attribute, $subject, $token)) {
            return false;
        }

        // #2838 — the uppercase alias must inherit the same guard.
        if ('delete' !== $attribute && 'DELETE' !== $attribute) {
            return true;
        }

        // Built-in protection: even a Modeler with `modeling.delete_custom`
        // must not delete a built-in row. The repository / controller may
        // also enforce this, but the voter is the authoritative gate so
        // every entry point (REST + GraphQL + future agent) inherits it.
        return !($subject instanceof ObjectType && $subject->isBuiltIn());
    }
}
