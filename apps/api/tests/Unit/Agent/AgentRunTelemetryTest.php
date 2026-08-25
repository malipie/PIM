<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\Domain\AgentRunSurface;
use App\Agent\Domain\Entity\AgentRun;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AgentRunTelemetryTest extends TestCase
{
    #[Test]
    public function recordsTheLatestQueueDelayAndAccumulatesLlmCalls(): void
    {
        $run = new AgentRun(Uuid::v7(), AgentRunSurface::Chat, 'work');

        $run->recordDequeued(1_000, 1_125);
        $run->recordDequeued(2_000, 2_040);
        $run->recordLlmCall(300, 50, 100, 20);
        $run->recordLlmCall(200, 30, 80, 10);

        self::assertSame(40, $run->getQueueDelayMs());
        self::assertSame(2, $run->getLlmCalls());
        self::assertSame(500, $run->getLlmDurationMs());
        self::assertSame(80, $run->getLlmTtftMs());
        self::assertSame(180, $run->getCacheReadTokens());
        self::assertSame(30, $run->getCacheCreationTokens());
    }
}
