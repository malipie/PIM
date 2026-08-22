<?php

declare(strict_types=1);

namespace App\Tests\Architecture\PHPStan\Fixtures;

use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Tenant\TenantScopeBinder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Fixture for {@see \App\PHPStan\Rules\TenantBindingMustUseBinderRule} (#2978).
 *
 * The fix: all three layers bound together, released in a finally so a sweep
 * over several tenants cannot leak the previous one.
 */
final class GoodCommandUsingBinder extends Command
{
    public function __construct(
        private readonly TenantScopeBinder $tenantScope,
        private readonly Tenant $tenant,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->tenantScope->bind($this->tenant);
        try {
            return Command::SUCCESS;
        } finally {
            $this->tenantScope->release();
        }
    }
}
