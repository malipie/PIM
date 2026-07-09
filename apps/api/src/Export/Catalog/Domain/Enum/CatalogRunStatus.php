<?php

declare(strict_types=1);

namespace App\Export\Catalog\Domain\Enum;

/**
 * Lifecycle of a single PDF catalog generation run (ADR-0027 §6.3, CPDF-P1-01).
 */
enum CatalogRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Done = 'done';
    case Error = 'error';
    case Cancelled = 'cancelled';

    /**
     * Terminal states cannot be cancelled or re-entered.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Done, self::Error, self::Cancelled => true,
            self::Pending, self::Running => false,
        };
    }
}
