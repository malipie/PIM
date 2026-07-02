<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\DoctrineMigrations;

use Doctrine\Migrations\Version\Comparator;
use Doctrine\Migrations\Version\Version;

/**
 * AGENT-P1-01 (#1953) — order migrations by their VersionYYYYMMDDHHMMSS
 * timestamp across ALL namespaces.
 *
 * The default comparator sorts by FQCN, so a second migrations
 * namespace (AgentMigrations, ADR-0024 module-namespace migrations)
 * would run alphabetically BEFORE DoctrineMigrations — on a fresh
 * database the agent tables tried to reference `tenants` before the
 * core migration created it (Playwright CI). Timestamp order restores
 * the intended global chronology regardless of namespace.
 */
final class TimestampVersionComparator implements Comparator
{
    public function compare(Version $a, Version $b): int
    {
        return strcmp($this->sortKey((string) $a), $this->sortKey((string) $b));
    }

    private function sortKey(string $version): string
    {
        $lastSeparator = strrpos($version, '\\');
        $shortName = false === $lastSeparator ? $version : substr($version, $lastSeparator + 1);

        // "Version20260702150000" -> "20260702150000"; fall back to the
        // full short name for anything not matching the convention.
        return str_starts_with($shortName, 'Version') ? substr($shortName, 7) : $shortName;
    }
}
