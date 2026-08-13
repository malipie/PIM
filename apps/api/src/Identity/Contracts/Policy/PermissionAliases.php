<?php

declare(strict_types=1);

namespace App\Identity\Contracts\Policy;

/**
 * #2838 — equivalence between the two permission vocabularies.
 *
 * The codebase carries two catalogues of permission codes: the Sprint-0
 * `{resource}.{action}` grid (`object.read`, `object.write`, …) and the
 * PRD §3.2 business codes (`products.view`, `categories.add_edit`, …).
 * Roles seeded from the PRD templates hold only the latter, while several
 * gates still ask for the former — so a Catalog Manager was refused
 * capabilities it demonstrably has, most visibly the agent tools, which
 * disappeared entirely for that role.
 *
 * This map says which PRD codes satisfy a legacy requirement. It is
 * deliberately narrow: only reads-for-reads and writes-for-writes on the
 * same subject matter. Nothing here grants schema modelling — that stays
 * behind `modeling.*` in every path.
 *
 * Temporary by design. Once the two catalogues are unified (the legacy
 * grid retired in favour of PRD codes), this class goes away with them.
 */
final class PermissionAliases
{
    /**
     * Legacy code → PRD codes that also satisfy it.
     *
     * @var array<string, list<string>>
     */
    private const array EQUIVALENTS = [
        'object.read' => ['products.view', 'categories.view', 'multimedia.view'],
        'object.write' => ['products.edit', 'products.add', 'categories.add_edit'],
        'object.delete' => ['products.delete', 'categories.delete'],
        'object_type.read' => ['modeling.view', 'products.view', 'categories.view', 'multimedia.view'],
        'attribute.read' => ['modeling.view', 'products.view'],
        'attribute_group.read' => ['modeling.view', 'products.view'],
        'category.read' => ['categories.view'],
        'category.write' => ['categories.add_edit'],
        'asset.read' => ['multimedia.view'],
        'asset.write' => ['multimedia.add_edit_own', 'multimedia.add_edit_any'],
    ];

    /**
     * Every code that satisfies `$code`, itself first.
     *
     * @return list<string>
     */
    public static function acceptedFor(string $code): array
    {
        return [$code, ...(self::EQUIVALENTS[$code] ?? [])];
    }
}
