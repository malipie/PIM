<?php

declare(strict_types=1);

namespace App\Agent\Presentation\Command;

use App\Agent\Application\Run\AgentRunStarter;
use App\Agent\Domain\AgentRunSurface;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Tenant\TenantScopeBinder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P1-03 (#1955) — operator smoke entry for the loop before the
 * public API lands (P4-01): starts an async run in the given user's
 * scope. With no BYOK key configured the guard refuses - which is
 * itself the live wire-through smoke of dispatch/worker/guard.
 */
#[AsCommand(
    name: 'pim:agent:start',
    description: 'Start an agent run (async) for a tenant/user - smoke entry until the public API (P4-01).',
)]
final class StartAgentRunCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantScopeBinder $tenantScope,
        private readonly AgentRunStarter $starter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('tenant-code', InputArgument::REQUIRED, 'Tenant code (e.g. demo)')
            ->addArgument('user-id', InputArgument::REQUIRED, 'Initiating user UUID (the run acts within their permissions)')
            ->addArgument('intent', InputArgument::REQUIRED, 'Natural-language intent');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tenantCode = (string) $input->getArgument('tenant-code'); // @phpstan-ignore cast.useless
        $tenant = $this->entityManager->getRepository(Tenant::class)->findOneBy(['code' => $tenantCode]);
        if (!$tenant instanceof Tenant) {
            $io->error(\sprintf('Unknown tenant "%s".', $tenantCode));

            return Command::FAILURE;
        }

        $this->tenantScope->bind($tenant);

        $run = $this->starter->start(
            $tenant,
            Uuid::fromString((string) $input->getArgument('user-id')), // @phpstan-ignore cast.useless
            AgentRunSurface::Chat,
            (string) $input->getArgument('intent'), // @phpstan-ignore cast.useless
        );

        $io->success(\sprintf('Agent run %s dispatched (status: %s).', $run->getId()->toRfc4122(), $run->getStatus()->value));

        return Command::SUCCESS;
    }
}
