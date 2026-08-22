<?php

declare(strict_types=1);

namespace App\Tests\Architecture\PHPStan\Fixtures;

use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Fixture for {@see \App\PHPStan\Rules\TenantBindingMustUseBinderRule} (#2978).
 *
 * The defect: a console command binds TenantContext alone, so the Postgres
 * GUC stays empty and RLS hides every row from `pim_app`.
 */
final class BadCommandBindingContextDirectly extends Command
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly Tenant $tenant,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->tenantContext->set($this->tenant);

        return Command::SUCCESS;
    }
}
