<?php

declare(strict_types=1);

namespace App\Export\Contracts;

use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P3-06 (#1966) — trigger an export as an ACTION (no approval
 * diff: nothing in the catalog changes). The adapter creates an
 * ExportSession (source=agent) and dispatches the same RunExportMessage
 * the admin UI uses, so progress streams over the existing export
 * Mercure topic and the file lands in the same storage.
 */
interface ExportTriggerPort
{
    /**
     * @param array<string, mixed> $filterDsl selector ([] = every object of the type)
     * @param list<string>         $columns   column keys (built-ins like sku/status/completeness_pct or attribute codes)
     * @param string               $format    xlsx or csv - validated at runtime (xml is feed territory, P7-01)
     *
     * @return Uuid the export session id (poll /api/exports/sessions/{id})
     *
     * @throws InvalidArgumentException on unknown object type / format / invalid DSL
     */
    public function trigger(
        Uuid $userId,
        string $objectTypeCode,
        array $filterDsl,
        array $columns,
        string $format,
    ): Uuid;
}
