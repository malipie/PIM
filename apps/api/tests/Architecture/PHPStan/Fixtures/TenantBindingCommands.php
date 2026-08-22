<?php

declare(strict_types=1);

namespace App\Tests\Architecture\PHPStan\Fixtures;

use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Tenant\TenantScopeBinder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Fixtures for {@see \App\PHPStan\Rules\TenantBindingMustUseBinderRule} (#2978).
 *
 * Each class is one shape the rule has to judge; the assertions live in
 * TenantBindingMustUseBinderRuleTest and reference these line numbers.
 */
final class BadCommandBindingContextDirectly extends Command
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly Tenant $tenant,
    ) {
        parent::__construct();
    }

    /** The defect: TenantContext alone, so the RLS GUC stays empty. */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->tenantContext->set($this->tenant);

        return Command::SUCCESS;
    }
}

/** Releasing the scope by hand has the same hole in the other direction. */
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

/** The fix: all three layers bound together. */
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

/**
 * Outside a console command a bare set() is legitimate — the entry point
 * (request listener / worker middleware) already established the GUC. The
 * rule must stay quiet here, or it gets suppressed and stops guarding.
 */
final class GoodServiceBindingContextDirectly
{
    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    public function rebind(Tenant $tenant): void
    {
        $this->tenantContext->set($tenant);
    }
}

/** Reading the context is not binding it. */
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
