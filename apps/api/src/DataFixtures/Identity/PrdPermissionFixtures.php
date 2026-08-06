<?php

declare(strict_types=1);

namespace App\DataFixtures\Identity;

use App\Identity\Application\PrdPermissionSeeder;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * RBAC-P1-006 (#645) — seeds the ~50 atomic permissions from
 * PRD-PIM-rbac §3.2 macierz as a globally-immutable pool.
 *
 * Resource/action split logic:
 *   PRD codes are formatted as `{module}.{action}` or
 *   `{module}.{submodule}.{action}` (3-segment). The seeder splits on
 *   the LAST dot, so `settings.users.manage` becomes
 *   resource=`settings.users`, action=`manage` — preserving the namespace
 *   in resource while keeping the verb in action. The full PRD code
 *   stays as `code` (unique by separate constraint).
 *
 * The catalogue itself now lives in {@see PrdPermissionSeeder} so production
 * (where this fixtures class does not exist) seeds the exact same list —
 * see that class for the incident that motivated the move.
 *
 * Idempotency:
 *   Fixture checks `permissions.code` before INSERT — re-running it after
 *   a partial seed (or alongside the existing legacy `RbacMatrix` 76-row
 *   set) leaves the legacy rows untouched and adds only the missing PRD
 *   codes. The two sets coexist until Phase 6 retrofit (#714-#717)
 *   consolidates Voters onto PRD codes and drops the legacy.
 *
 * Co-existence with legacy `RbacMatrix`:
 *   The legacy 76-row set (resource ∈ ['object','asset',...] × action ∈
 *   ['read','write','delete','admin']) is what current Voters consume.
 *   The new PRD set lives alongside it and is the substrate Phase 2
 *   PermissionResolver / Phase 3 Voters will switch to. No data loss
 *   when the legacy is eventually dropped — the seeder is replayable.
 */
final class PrdPermissionFixtures extends Fixture
{
    public function __construct(private readonly PrdPermissionSeeder $seeder)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $this->seeder->seed();
    }
}
