<?php

declare(strict_types=1);

namespace App\Export\Feed\Domain\Enum;

/**
 * Lifecycle of a single feed regeneration run (ADR-0023 §6.2, XMLF-P1-02).
 */
enum FeedRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Done = 'done';
    case Error = 'error';
    case Cancelled = 'cancelled';
}
