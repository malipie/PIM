<?php

declare(strict_types=1);

namespace App\Tests\Integration\Scheduler;

use App\Shared\Infrastructure\Scheduler\MaintenanceSchedule;
use App\Shared\Infrastructure\Scheduler\RunMaintenanceCommand;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * AUD-051 (W2-11) — proves the maintenance retention/offboarding schedule
 * registers every retention command as a recurring message.
 *
 * Before the fix the schedule provider did not exist (Symfony Scheduler unused),
 * so the retention commands never ran on a cadence and `audit_logs` / exports /
 * staged files / soft-deleted tenants grew without bound. This asserts the
 * declarative schedule contains exactly the expected commands — without booting
 * a worker (which the live-stack smoke covers via `debug:scheduler`).
 */
final class MaintenanceScheduleTest extends KernelTestCase
{
    #[Test]
    public function registersEveryRetentionCommandAsRecurringMessage(): void
    {
        self::bootKernel();

        $provider = self::getContainer()->get(MaintenanceSchedule::class);
        self::assertInstanceOf(MaintenanceSchedule::class, $provider);

        $messages = $provider->getSchedule()->getRecurringMessages();
        $commands = [];
        foreach ($messages as $recurring) {
            self::assertInstanceOf(RecurringMessage::class, $recurring);
            $trigger = $recurring->getTrigger();
            $context = new MessageContext('maintenance', $recurring->getId(), $trigger, new DateTimeImmutable());
            foreach ($recurring->getMessages($context) as $message) {
                self::assertInstanceOf(
                    RunMaintenanceCommand::class,
                    $message,
                    'Every scheduled message must be a RunMaintenanceCommand.',
                );
                $commands[] = $message->command;
            }
        }

        sort($commands);
        self::assertSame(
            [
                'pim:audit:cleanup',
                'pim:dashboard:snapshot',
                'pim:exports:cleanup',
                'pim:import:purge-staged',
                'pim:tenants:purge-deleted',
            ],
            $commands,
            'The maintenance schedule must drive every retention/offboarding/snapshot command.',
        );
    }
}
