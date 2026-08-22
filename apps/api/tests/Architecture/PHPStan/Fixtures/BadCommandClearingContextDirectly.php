<?php

declare(strict_types=1);

namespace App\Tests\Architecture\PHPStan\Fixtures;

use App\Shared\Application\TenantContext;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Fixture for {@see \App\PHPStan\Rules\TenantBindingMustUseBinderRule} (#2978).
 *
 * Releasing the scope by hand leaves the same hole in the other direction:
 * the GUC keeps naming a tenant the PHP side has already forgotten.
 */
final class BadCommandClearingContextDirectly extends Command
{
    public function __construct(private readonly TenantContext $tenantContext)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->tenantContext->clear();

        return Command::SUCCESS;
    }
}
