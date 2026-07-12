<?php

declare(strict_types=1);

namespace App\Workflow\Contracts;

use Symfony\Component\Workflow\WorkflowInterface;

/**
 * WFL-P5-01 (#2431) — resolves the machine governing a catalog object:
 * the static `object_editorial` YAML by default, the tenant's enabled
 * DB definition when WORKFLOW_CUSTOM_DEFINITIONS is on (ObjectType-
 * specific beats tenant-global). Subject is typed `object` on purpose —
 * this contract may only depend on Shared+Vendor (deptrac), and the
 * concrete subject class lives in Catalog.
 */
interface EditorialWorkflowProviderInterface
{
    public function for(object $subject, ?string $objectTypeId = null): WorkflowInterface;

    /**
     * #2513 — the configured recipient of auto-created review /
     * request-unpublish tasks for the governing definition (same
     * resolution as {@see self::for()}). Null when custom definitions
     * are off, no enabled definition applies, or the definition leaves
     * the reviewer unset — the caller then falls back to the built-in
     * reviewer role.
     */
    public function reviewerFor(?string $objectTypeId): ?TaskAssignee;
}
