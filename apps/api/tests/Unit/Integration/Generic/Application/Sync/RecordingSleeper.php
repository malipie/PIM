<?php

declare(strict_types=1);

namespace App\Tests\Unit\Integration\Generic\Application\Sync;

use App\Integration\Generic\Application\Sleeper;

/**
 * Test double for {@see Sleeper}: records requested delays instead of blocking,
 * so throttle-backoff and rate-pacing assertions run without wall-clock time.
 */
final class RecordingSleeper implements Sleeper
{
    /** @var list<int> */
    public array $sleeps = [];

    public function sleep(int $seconds): void
    {
        $this->sleeps[] = $seconds;
    }
}
