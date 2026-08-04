<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Messenger;

use App\Catalog\Contracts\Event\ObjectSubmittedForReview;
use App\Identity\Contracts\Policy\PermissionGranteesInterface;
use App\Notification\Contracts\NotifierPort;
use App\Notification\Infrastructure\Messenger\WorkflowNotificationFanOut;
use App\Shared\Application\TenantContext;
use App\Workflow\Contracts\TransitionLogPort;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\Uid\Uuid;

/**
 * #2734 — a missing tenant context on the worker still drops the notification
 * (recipients cannot be resolved), but it must leave a warning trace instead of
 * vanishing silently.
 */
final class WorkflowNotificationFanOutTest extends TestCase
{
    #[Test]
    public function missingTenantContextDropsWithWarningAndNoNotify(): void
    {
        $notifier = $this->createMock(NotifierPort::class);
        $notifier->expects(self::never())->method('notifyUsers');

        $logger = new class extends AbstractLogger {
            /** @var list<array{mixed, string|Stringable}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, $message];
            }
        };

        $fanOut = new WorkflowNotificationFanOut(
            $notifier,
            $this->createStub(PermissionGranteesInterface::class),
            $this->createStub(TransitionLogPort::class),
            new TenantContext(),
            $logger,
        );

        $fanOut->onSubmittedForReview(new ObjectSubmittedForReview(Uuid::v7(), Uuid::v7()));

        self::assertCount(1, $logger->records);
        [$level, $message] = $logger->records[0];
        self::assertSame('warning', $level);
        self::assertStringContainsString('no tenant context', (string) $message);
    }
}
