<?php

declare(strict_types=1);

namespace App\Export\Feed\Domain\Enum;

/**
 * Severity of a per-product / per-slot feed-run log line (ADR-0023 §6.2,
 * XMLF-P1-02). Drives the "feed health" report (XMLF-P4-03).
 */
enum FeedRunLogLevel: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
}
