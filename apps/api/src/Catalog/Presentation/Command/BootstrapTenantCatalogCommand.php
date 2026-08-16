<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Command;

use App\Catalog\Application\BuiltInObjectTypeSeeder;
use App\Catalog\Contracts\Service\TenantCatalogBootstrap;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Repository\TenantRepositoryInterface;
use App\Shared\Domain\Tenant;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * #2875 — repairs tenants provisioned before the fix.
 *
 * `SuperAdminTenantWriteController` seeded roles and nothing else, so every
 * tenant created through the admin UI came up with no ObjectTypes, no system
 * attributes and no menu rows. Their owners opened Products and were told
 * the built-in product type was missing.
 *
 * New tenants no longer need this — the controller runs the same bootstrap
 * itself. This exists for the ones already out there, and stays useful as
 * the idempotent "make this tenant whole again" button.
 */
#[AsCommand(
    name: 'pim:tenant:bootstrap-catalog',
    description: 'Seed built-in ObjectTypes, system/relation attributes and the default menu for tenants missing them (idempotent).',
)]
final class BootstrapTenantCatalogCommand extends Command
{
    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly ObjectTypeRepositoryInterface $objectTypes,
        private readonly TenantCatalogBootstrap $bootstrap,
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenantContext,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Tenant code. Omit to cover every tenant.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be seeded and change nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $code = $input->getOption('tenant');
        $dryRun = $input->getOption('dry-run');

        $tenants = \is_string($code) && '' !== $code
            ? array_filter([$this->tenants->findByCode($code)])
            : $this->tenants->findAllOrderedByCode();

        if ([] === $tenants) {
            $io->error(\is_string($code) ? \sprintf('No tenant with code `%s`.', $code) : 'No tenants found.');

            return Command::FAILURE;
        }

        $rows = [];
        $blocked = [];
        foreach ($tenants as $tenant) {
            $code = $tenant->getCode();
            $this->bindTenant($tenant);

            try {
                $before = \count($this->objectTypes->findAllByTenant($tenant));
                if (!$dryRun) {
                    $this->bootstrap->bootstrap($tenant);
                    $this->em->flush();
                }
                $after = \count($this->objectTypes->findAllByTenant($tenant));

                // A built-in code can be occupied by a CUSTOM type the tenant
                // built itself. The seeder skips those rather than adopting
                // them, so without this the run reports success and the
                // tenant quietly stays short of a built-in.
                foreach (BuiltInObjectTypeSeeder::builtInCodes() as $builtInCode) {
                    $occupant = $this->objectTypes->findByCode($builtInCode, $tenant);
                    if (null !== $occupant && !$occupant->isBuiltIn()) {
                        $blocked[] = \sprintf('%s → kod `%s` zajmuje typ własny', $code, $builtInCode);
                    }
                }
            } finally {
                $this->unbindTenant();
            }

            $rows[] = [$code, $before, $dryRun ? '—' : $after];
        }

        $io->table(['tenant', 'typów przed', 'typów po'], $rows);

        if ([] !== $blocked) {
            $io->warning(array_merge(
                ['Wbudowane typy pominięte — kod zajęty przez typ własny:'],
                $blocked,
                ['Zmień kod typu własnego, potem uruchom ponownie. Komenda niczego nie nadpisuje.'],
            ));
        }

        if ($dryRun) {
            $io->note('Dry run — nothing was written.');
        } else {
            $io->success('Bootstrap complete.');
        }

        return Command::SUCCESS;
    }

    /**
     * Bind the tenant on BOTH isolation layers — the PHP-side TenantContext
     * (TenantFilter) and the Postgres `app.current_tenant` GUC (FORCE RLS) —
     * mirroring DetectAttributesDriftCommand. Without it the counts read as
     * zero for every tenant, because a CLI session has no tenant bound and
     * the filter hides everything.
     */
    private function bindTenant(Tenant $tenant): void
    {
        $this->tenantContext->set($tenant);
        // tenant-safe: infrastructure (establishes the tenant_id RLS policies read in this CLI session; this IS the tenant boundary, not a bypass)
        $this->connection->executeStatement(
            "SELECT set_config('app.current_tenant', :tenant_id, false)",
            ['tenant_id' => $tenant->getId()->toRfc4122()],
        );
        // tenant-safe: infrastructure (the maintenance CLI never runs as super-admin)
        $this->connection->executeStatement("SELECT set_config('app.is_super_admin', 'false', false)");
    }

    private function unbindTenant(): void
    {
        $this->tenantContext->clear();
        // tenant-safe: infrastructure (resets the RLS tenant marker so the next tenant in the sweep starts clean)
        $this->connection->executeStatement("SELECT set_config('app.current_tenant', '', false)");
    }
}
