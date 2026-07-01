<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Feed;

use App\Export\Feed\Domain\Entity\FeedRun;
use App\Export\Feed\Domain\Entity\FeedRunLog;
use App\Export\Feed\Domain\Enum\FeedRunLogLevel;
use App\Export\Feed\Domain\Enum\FeedRunStatus;
use App\Export\Feed\Domain\Enum\FeedRunTrigger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * XMLF-P1-02 — FeedRun / FeedRunLog domain behaviour.
 */
final class FeedRunTest extends TestCase
{
    #[Test]
    public function newRunIsPendingWithZeroCounters(): void
    {
        $run = new FeedRun(Uuid::v7(), FeedRunTrigger::Manual);

        self::assertSame(FeedRunStatus::Pending, $run->getStatus());
        self::assertSame(FeedRunTrigger::Manual, $run->getTrigger());
        self::assertSame(0, $run->getItemCount());
        self::assertSame(0, $run->getSkippedCount());
        self::assertNull($run->getCompletedAt());
    }

    #[Test]
    public function markDoneStoresCountersFileAndCompletion(): void
    {
        $run = new FeedRun(Uuid::v7(), FeedRunTrigger::Schedule);
        $run->markRunning();
        self::assertSame(FeedRunStatus::Running, $run->getStatus());

        $run->markDone(12_408, 34, 34, 'feeds/t/google_pl.xml', 18_700_000, 22_000);

        self::assertSame(FeedRunStatus::Done, $run->getStatus());
        self::assertSame(12_408, $run->getItemCount());
        self::assertSame(34, $run->getSkippedCount());
        self::assertSame('feeds/t/google_pl.xml', $run->getFilePath());
        self::assertSame(18_700_000, $run->getFileSizeBytes());
        self::assertSame(22_000, $run->getDurationMs());
        self::assertNotNull($run->getCompletedAt());
    }

    #[Test]
    public function markErrorRecordsMessage(): void
    {
        $run = new FeedRun(Uuid::v7(), FeedRunTrigger::Schedule);
        $run->markError('missing required <price>');

        self::assertSame(FeedRunStatus::Error, $run->getStatus());
        self::assertSame('missing required <price>', $run->getErrorMessage());
        self::assertNotNull($run->getCompletedAt());
    }

    #[Test]
    public function logCarriesLevelSkuSlotAndMessage(): void
    {
        $runId = Uuid::v7();
        $log = new FeedRunLog($runId, FeedRunLogLevel::Warning, 'missing g:gtin — skipped', 'KL-PT-80240', 'g:gtin');

        self::assertTrue($log->getFeedRunId()->equals($runId));
        self::assertSame(FeedRunLogLevel::Warning, $log->getLevel());
        self::assertSame('KL-PT-80240', $log->getObjectSku());
        self::assertSame('g:gtin', $log->getSlot());
        self::assertSame('missing g:gtin — skipped', $log->getMessage());
    }
}
