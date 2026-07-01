<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Feed;

use App\Export\Feed\Domain\Message\RunFeedMessage;
use App\Shared\Application\TenantAwareMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * XMLF-P4-02 — the async envelope must declare its tenant (HIGH-002): the
 * worker's rebinding middleware reads it before the handler touches any
 * tenant-scoped row.
 */
final class RunFeedMessageTest extends TestCase
{
    #[Test]
    public function exposesTheTenantForTheRebindingMiddleware(): void
    {
        $runId = Uuid::v7();
        $tenantId = Uuid::v7();

        $message = new RunFeedMessage($runId, $tenantId);

        self::assertInstanceOf(TenantAwareMessage::class, $message);
        self::assertTrue($message->tenantId()->equals($tenantId));
        self::assertTrue($message->feedRunId->equals($runId));
    }
}
