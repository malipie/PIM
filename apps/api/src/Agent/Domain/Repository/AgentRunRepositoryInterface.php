<?php

declare(strict_types=1);

namespace App\Agent\Domain\Repository;

use App\Agent\Domain\Entity\AgentRun;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P1-01 (#1953) — persistence port of the run lifecycle. Reads
 * are tenant-scoped by the Doctrine TenantFilter (+ RLS underneath).
 */
interface AgentRunRepositoryInterface
{
    public function save(AgentRun $run): void;

    public function find(Uuid $id): ?AgentRun;
}
