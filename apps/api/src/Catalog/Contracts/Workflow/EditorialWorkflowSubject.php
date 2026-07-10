<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Workflow;

use App\Shared\Domain\Tenant;
use Symfony\Component\Uid\Uuid;

/**
 * WFL-P0-04 (#2413) — the minimal identity a workflow listener needs
 * from the subject of the `object_editorial` state machine. The subject
 * is a Catalog entity (marking lives on `objects.status`, ADR-0029
 * pillar 3), but the Workflow BC may only depend on `Catalog_Contracts`
 * (deptrac) — this seam carries id + tenant across without leaking the
 * entity. Same shape as the AUD-053 `CurrentUserProvider` seam.
 */
interface EditorialWorkflowSubject
{
    public function getId(): Uuid;

    public function getTenant(): ?Tenant;
}
