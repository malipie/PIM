<?php

declare(strict_types=1);

namespace App\Tests\Architecture\PHPStan\Fixtures;

use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;

/**
 * Fixture for {@see \App\PHPStan\Rules\TenantBindingMustUseBinderRule} (#2978).
 *
 * Outside a console command a bare set() is legitimate: the entry point
 * (request listener / worker middleware) has already established the GUC.
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
