<?php

declare(strict_types=1);

namespace App\Workflow\Contracts;

/**
 * WFL-P4-01 (#2428) — task lifecycle. Open tasks are the inbox; done
 * carries resolved_by/resolved_at; cancelled closes without an outcome.
 */
enum WorkflowTaskStatus: string
{
    case Open = 'open';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return self::Open !== $this;
    }
}
