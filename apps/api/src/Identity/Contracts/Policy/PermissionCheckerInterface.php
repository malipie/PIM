<?php

declare(strict_types=1);

namespace App\Identity\Contracts\Policy;

use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P0-07 (#1950) — coarse RBAC check by user id, exposed as a
 * Contracts seam so the removable Agent BC can filter its tool registry
 * per user (ADR-0024 b) without reaching Identity internals.
 *
 * Permission codes follow the RBAC matrix `resource.action` format
 * (PRD-PIM-rbac §3.2), e.g. `object.read`, `attribute.write`.
 *
 * This is the COARSE gate (tool visible / executable at all); the
 * fine-grained per-attribute/locale/channel enforcement on every
 * tool-call is layered on top in AGENT-P1-02.
 */
interface PermissionCheckerInterface
{
    public function userHasPermission(Uuid $userId, string $permissionCode): bool;
}
