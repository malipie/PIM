<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Query;

use InvalidArgumentException;

/**
 * AGENT-P2-03 (#1960) — Contracts seam over the completeness scoring
 * data (objects.completeness_pct + attributes_indexed presence of the
 * ObjectType's completeness_rules.required codes). Read-only aggregate;
 * tenant scope comes from the Doctrine TenantFilter / explicit tenant
 * predicates inside the implementation.
 */
interface CompletenessReportPort
{
    /**
     * @throws InvalidArgumentException when the object type code is unknown
     */
    public function report(string $objectTypeCode, int $thresholdPct = 90): CompletenessReport;
}
