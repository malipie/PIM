<?php

declare(strict_types=1);

namespace App\Agent\Presentation\Command;

use App\Agent\Application\Content\AiContentDefaultsSeeder;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * AICG-P1-04 (#2330) — seeds the built-in content recipes + default
 * brand voice for every tenant (or one, via --tenant). Idempotent;
 * ships with the removable Agent BC — core fixtures must not import
 * App\Agent\*, so dev/demo provisioning goes through this command.
 */
#[AsCommand(
    name: 'pim:agent:seed-content-defaults',
    description: 'Seed built-in content recipes and the default brand voice per tenant (idempotent).',
)]
final class SeedAiContentDefaultsCommand extends Command
{
    public function __construct(
        private readonly AiContentDefaultsSeeder $seeder,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Seed a single tenant by code instead of all tenants.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tenantCode = $input->getOption('tenant');

        $criteria = \is_string($tenantCode) && '' !== $tenantCode ? ['code' => $tenantCode] : [];
        /** @var list<Tenant> $tenants */
        $tenants = $this->em->getRepository(Tenant::class)->findBy($criteria);
        if ([] === $tenants) {
            $io->error(\is_string($tenantCode) ? \sprintf('Tenant "%s" not found.', $tenantCode) : 'No tenants found.');

            return Command::FAILURE;
        }

        $total = 0;
        foreach ($tenants as $tenant) {
            $created = $this->seeder->seed($tenant);
            $total += $created;
            $io->writeln(\sprintf('%s: %d row(s) created', $tenant->getCode(), $created));
        }

        $io->success(\sprintf('Done — %d row(s) created across %d tenant(s).', $total, \count($tenants)));

        return Command::SUCCESS;
    }
}
