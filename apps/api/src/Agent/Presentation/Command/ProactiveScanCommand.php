<?php

declare(strict_types=1);

namespace App\Agent\Presentation\Command;

use App\Agent\Application\Proactive\ProactiveStewardScanner;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P8-01 (#1983) — schedulable entry point for the proactive
 * data-steward scan (cron / scheduler worker). One tenant per
 * invocation; the steward user id anchors RBAC and the §8.5 budgets.
 */
#[AsCommand(
    name: 'pim:agent:proactive-scan',
    description: 'Run the proactive data-steward scan (opt-in) for a tenant and open a findings run',
)]
final class ProactiveScanCommand extends Command
{
    public function __construct(
        private readonly ProactiveStewardScanner $scanner,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
        private readonly TenantFilterConfigurator $tenantFilter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('tenant-code', InputArgument::REQUIRED, 'Tenant code to scan');
        $this->addArgument('steward-user-id', InputArgument::REQUIRED, 'User UUID the findings run belongs to');
        $this->addArgument('object-type', InputArgument::OPTIONAL, 'ObjectType code to scan', 'product');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tenantCode = $input->getArgument('tenant-code');
        $tenant = $this->entityManager->getRepository(Tenant::class)->findOneBy(['code' => $tenantCode]);
        if (!$tenant instanceof Tenant) {
            $io->error(\sprintf('Unknown tenant "%s".', $tenantCode));

            return Command::FAILURE;
        }
        $this->tenantContext->set($tenant);
        $this->tenantFilter->apply();

        $stewardId = $input->getArgument('steward-user-id');
        if (!Uuid::isValid($stewardId)) {
            $io->error('steward-user-id must be a UUID.');

            return Command::FAILURE;
        }

        $run = $this->scanner->scanTenant($tenant, Uuid::fromString($stewardId), $input->getArgument('object-type'));

        if (null === $run) {
            $io->success('Scan finished: proactivity disabled or nothing to report.');

            return Command::SUCCESS;
        }

        $io->success(\sprintf('Findings run opened: %s (awaiting input).', $run->getId()->toRfc4122()));

        return Command::SUCCESS;
    }
}
