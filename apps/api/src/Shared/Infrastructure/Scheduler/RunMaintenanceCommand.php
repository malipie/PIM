<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Scheduler;

use App\Shared\Application\TenantAgnosticMessage;

/**
 * AUD-051 (W2-11) — message that asks the worker to run one maintenance console
 * command on its scheduled cadence.
 *
 * Symfony Scheduler has no built-in "run a console command" recurring message,
 * so {@see MaintenanceSchedule} emits this and {@see RunMaintenanceCommandHandler}
 * dispatches it to the Symfony console application. Keeping the command name +
 * args in the payload (rather than one message class per command) keeps the
 * schedule declarative and the handler trivially testable.
 *
 * Tenant-agnostic by nature (#2803): the scheduled commands sweep across
 * the whole installation — `pim:tenants:purge-deleted` inspects every
 * tenant, `pim:audit:cleanup` every audit row. Binding this payload to one
 * tenant would be wrong rather than merely redundant, so it declares
 * {@see TenantAgnosticMessage} and the rebinding middleware lets it through
 * with an empty context. Without that marker the middleware rejected every
 * firing, the five daily maintenance jobs never ran once in production, and
 * the worker killed itself every five failures.
 */
final readonly class RunMaintenanceCommand implements TenantAgnosticMessage
{
    /**
     * @param array<string, scalar|bool> $arguments console input (options/args), e.g. `['--dry-run' => true]`
     */
    public function __construct(
        public string $command,
        public array $arguments = [],
    ) {
    }
}
