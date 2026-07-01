<?php

declare(strict_types=1);

namespace App\Export\Feed\Domain\Enum;

/**
 * Per-feed policy for products that fail required-field validation during
 * generation (ADR-0023 §6.5, XMLF-P1-01). `skip_invalid` (default) drops the
 * product and logs a warning; `include_with_warning` keeps it in the feed.
 */
enum FeedValidationPolicy: string
{
    case SkipInvalid = 'skip_invalid';
    case IncludeWithWarning = 'include_with_warning';
}
