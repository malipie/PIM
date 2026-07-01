<?php

declare(strict_types=1);

namespace App\Export\Feed\Domain\Enum;

/**
 * What started a feed regeneration (ADR-0023 §6.2, XMLF-P1-02): the cron
 * scheduler, a manual "Regenerate now", or the first publish on create.
 */
enum FeedRunTrigger: string
{
    case Schedule = 'schedule';
    case Manual = 'manual';
    case FirstPublish = 'first_publish';
}
