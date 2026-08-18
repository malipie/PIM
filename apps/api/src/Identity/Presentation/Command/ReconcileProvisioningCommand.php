<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Command;

use App\Identity\Infrastructure\Provisioning\ProvisioningQueue;
use App\Identity\Infrastructure\Provisioning\ProvisioningReconciler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * TNT-P4-08 (#2909 / ADR-0036) — przepisuje wyniki zleceń provisioningu do
 * rejestru tenantów.
 *
 * Provisioner nie ma sieci i nie zadzwoni z wynikiem; zostawia go plikiem.
 * Panel rozlicza zlecenia przy okazji odpytywania o postęp, ale operator
 * zamyka przeglądarkę, a instancja wstaje dalej — ta komenda jest tą pewną
 * ścieżką. Bezpieczna do wołania z cronu: rozliczone zlecenia są pomijane po
 * znaczniku, więc powtórzenie niczego nie nadpisuje.
 *
 * Nie jest wpięta w {@see \App\Shared\Infrastructure\Scheduler\MaintenanceSchedule}
 * ŚWIADOMIE: harmonogram jedzie transportem `scheduler_maintenance`, który
 * konsumuje `worker`, a stack instancji platformowej workera nie ma (i nie
 * potrzebuje — platforma nie przetwarza katalogu). Wołanie idzie z crona
 * hosta obok kopii zapasowych.
 */
#[AsCommand(
    name: 'pim:tenants:reconcile-provisioning',
    description: 'Przepisuje wyniki zleceń provisioningu do rejestru tenantów.',
)]
final class ReconcileProvisioningCommand extends Command
{
    public function __construct(
        private readonly ProvisioningQueue $queue,
        private readonly ProvisioningReconciler $reconciler,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->queue->isAvailable()) {
            // To NIE jest błąd: instancja tenanta nie ma kolejki i nie ma jej
            // mieć. Komenda jest w obrazie wspólnym dla wszystkich instancji.
            $io->note('Kolejka provisioningu jest niedostępna — ta instancja niczego nie rozlicza.');

            return Command::SUCCESS;
        }

        $count = $this->reconciler->reconcile();

        if (0 === $count) {
            $io->success('Brak zleceń do rozliczenia.');

            return Command::SUCCESS;
        }

        $io->success(\sprintf('Rozliczono %d zlecen(ia) provisioningu.', $count));

        return Command::SUCCESS;
    }
}
