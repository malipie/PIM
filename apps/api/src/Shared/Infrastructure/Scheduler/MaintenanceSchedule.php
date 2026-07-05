<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Scheduler;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * AUD-051 (W2-11) — the platform's retention / offboarding maintenance schedule.
 *
 * Before this, none of the retention commands were scheduled — Symfony Scheduler
 * was unused and there was no cron, so `audit_logs` grew without bound, the
 * tenant soft-delete window never closed, abandoned staged uploads lingered, and
 * (with AUD-050) free-tier exports were never swept. This provider registers
 * each command as a daily recurring message, drained off the dedicated
 * `scheduler_maintenance` transport.
 *
 * Cadence: daily is enough for every sweep here — the smallest retention window
 * (staged uploads, 24h) is still an order of magnitude longer than a daily run,
 * and the tenant grace period (30d) / audit horizon (365d) are far longer. Runs
 * are spread across off-peak early-morning UTC slots so they don't contend.
 *
 * Worker: the transport is consumed by `messenger:consume scheduler_maintenance`
 * (see messenger.yaml + docker-compose `worker`). Each message runs its console
 * command through {@see RunMaintenanceCommandHandler}.
 *
 * No --dry-run here: this is the real sweep. Operators validate candidates with
 * `--dry-run` manually (each command supports it) before relying on the schedule.
 */
#[AsSchedule('maintenance')]
final class MaintenanceSchedule implements ScheduleProviderInterface
{
    private ?Schedule $schedule = null;

    public function getSchedule(): Schedule
    {
        return $this->schedule ??= new Schedule()->add(
            // Free-tier export retention (AUD-050) — 02:00 UTC daily.
            RecurringMessage::cron('0 2 * * *', new RunMaintenanceCommand('pim:exports:cleanup')),
            // Audit-log retention horizon (#99) — 02:30 UTC daily.
            RecurringMessage::cron('30 2 * * *', new RunMaintenanceCommand('pim:audit:cleanup')),
            // Tenant soft-delete hard-delete sweep (RBAC-P5-021) — 03:00 UTC daily.
            RecurringMessage::cron('0 3 * * *', new RunMaintenanceCommand('pim:tenants:purge-deleted')),
            // Abandoned staged-upload TTL sweep (IMP2-2.2) — 03:30 UTC daily.
            RecurringMessage::cron('30 3 * * *', new RunMaintenanceCommand('pim:import:purge-staged')),
            // Dashboard KPI snapshot per tenant (DASH-05 #2257) — 03:45 UTC daily.
            RecurringMessage::cron('45 3 * * *', new RunMaintenanceCommand('pim:dashboard:snapshot')),
        );
    }
}
