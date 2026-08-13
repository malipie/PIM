<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security\Prd;

use App\Catalog\Domain\Entity\AttributeGroup;
use App\Identity\Infrastructure\Security\AbstractPrdVoter;

/**
 * RBAC-P3-004 (#667) — per-AttributeGroup authorization aligned with the
 * PRD §3.2 Modeling row (`modeling.view`, `modeling.attribute_groups.add_edit`).
 *
 * AttributeGroup has no separate `delete` code in the macierz — its
 * lifecycle is bundled into `add_edit`. There is also no delete action
 * exposed by the voter; the macierz simply does not surface group
 * deletion as a distinct permission. The controller relies on cascade
 * rules from the database (groups detached on attribute migration / type
 * change).
 */
final class AttributeGroupVoter extends AbstractPrdVoter
{
    /**
     * @return array<string, string|list<string>>
     */
    protected function permissionMap(): array
    {
        return [
            'view' => ['modeling.view', 'products.view', 'categories.view', 'multimedia.view'],
            'add_edit' => 'modeling.attribute_groups.add_edit',

            // #2838 — schema metadata is readable by whoever may read the
            // data it describes; requiring `modeling.view` would also open
            // the Modeling section in the sidebar, which PRD §3.2 denies to
            // catalog roles. Writes stay on `modeling.*`.
            'READ' => ['modeling.view', 'products.view', 'categories.view', 'multimedia.view'],
            'CREATE' => 'modeling.attribute_groups.add_edit',
            'UPDATE' => 'modeling.attribute_groups.add_edit',
            'WRITE' => 'modeling.attribute_groups.add_edit',
            'DELETE' => 'modeling.attribute_groups.add_edit',
        ];
    }

    protected function subjectClass(): string
    {
        return AttributeGroup::class;
    }
}
