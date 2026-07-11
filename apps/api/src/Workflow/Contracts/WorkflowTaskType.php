<?php

declare(strict_types=1);

namespace App\Workflow\Contracts;

/**
 * WFL-P4-01 (#2428) — task categories of the editorial loop. `custom`
 * covers operator-created todos; the other three are written by the
 * transition automation (WFL-P4-02).
 */
enum WorkflowTaskType: string
{
    case Review = 'review';
    case Fix = 'fix';
    case RequestUnpublish = 'request_unpublish';
    case Custom = 'custom';
}
