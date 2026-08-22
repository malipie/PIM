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
 * Reading the context is not binding it — the rule must stay quiet, or it
 * gets suppressed and stops guarding.
 */
final class GoodCommandReadingContext extends Command
{
    public function __construct(private readonly TenantContext $tenantContext)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->tenantContext->get() instanceof Tenant ? Command::SUCCESS : Command::FAILURE;
    }
}
