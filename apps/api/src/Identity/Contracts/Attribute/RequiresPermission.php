<?php

declare(strict_types=1);

namespace App\Identity\Contracts\Attribute;

use Attribute;

/**
 * Declares that the annotated controller action requires a specific RBAC
 * permission. Enforced at runtime by the Phase 3 EndpointGuardListener
 * (#664) and statically by RequiresPermissionAnnotationRule (this PR).
 *
 * Format mirrors the permission identifiers in PRD-PIM-rbac §3.2 macierz:
 * `{module}.{action}` — e.g. `products.edit`, `users.invite`,
 * `audit_log.read_own`.
 *
 * The `subject` parameter, when present, names the controller argument
 * (or property accessible from it) that the Voter receives as the
 * resource being authorised — exact same shape as Symfony's native
 * `#[IsGranted(..., subject: '...')]`.
 *
 * Resource policy slots (locale / channel / attribute_group) are derived
 * by the Voter from the subject + the active UserRole assignment scope.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class RequiresPermission
{
    /**
     * @param list<string> $anyOf #2838 — alternative codes, any one of which
     *                            satisfies the requirement. Multiple
     *                            `#[RequiresPermission]` attributes on one
     *                            method are ANDed by the guard, so alternatives
     *                            have to be expressed here. Used where a read is
     *                            implied by several different grants — e.g. list
     *                            schema metadata, which a Modeler reaches through
     *                            `modeling.view` and a Catalog Manager through
     *                            `products.view`.
     */
    public function __construct(
        public readonly string $module,
        public readonly string $action,
        public readonly ?string $subject = null,
        public readonly array $anyOf = [],
    ) {
    }

    public function permissionCode(): string
    {
        return $this->module.'.'.$this->action;
    }

    /**
     * Every code that satisfies this requirement, the primary one first.
     *
     * @return list<string>
     */
    public function acceptedCodes(): array
    {
        return [] === $this->anyOf ? [$this->permissionCode()] : $this->anyOf;
    }
}
