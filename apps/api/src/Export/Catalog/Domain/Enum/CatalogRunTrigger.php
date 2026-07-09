<?php

declare(strict_types=1);

namespace App\Export\Catalog\Domain\Enum;

/**
 * What started a PDF catalog generation run (ADR-0027 §6.3, CPDF-P1-01): a
 * manual "Generate now", the cron scheduler, or the agent.
 */
enum CatalogRunTrigger: string
{
    case Manual = 'manual';
    case Scheduled = 'scheduled';
    case Agent = 'agent';
}
