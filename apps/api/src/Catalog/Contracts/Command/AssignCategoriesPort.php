<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Command;

use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P3-05 (#1965) — category assignment THROUGH APPROVAL: instead
 * of touching the object_categories junction, materialize one
 * PendingChangeType::Category diff per matched object (before = the
 * object's current category-id list, after = operation + category ids).
 * The commit lands post-accept through the existing bulk category
 * handlers (P3-02 port), which write the undo-log the 24h rollback
 * already replays.
 */
interface AssignCategoriesPort
{
    /**
     * @param array<string, mixed> $filterDsl   selector ([] = every object of the type)
     * @param list<string>         $categoryIds category object UUIDs (RFC 4122)
     * @param string               $operation   add|remove|move - runtime-validated (a literal
     *                                          union here makes the adapter's defensive guard
     *                                          "always true" for PHPStan)
     *
     * @throws InvalidArgumentException on unknown object type / invalid DSL / unknown operation
     */
    public function materializeCategoryAssignments(
        Uuid $batchId,
        Uuid $userId,
        string $objectTypeCode,
        array $filterDsl,
        array $categoryIds,
        string $operation,
    ): CategoryAssignProposal;
}
