<?php

declare(strict_types=1);

namespace App\Tests\Architecture\PHPStan\Fixtures;

use Doctrine\DBAL\Connection;

/**
 * Fixture for {@see \App\PHPStan\Rules\RawTenantGucRule} (#2978).
 *
 * The break-glass flag is the same boundary seen from the other side.
 */
final class BadServiceWritingSuperAdminGuc
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function elevate(): void
    {
        $this->connection->executeStatement("SELECT set_config('app.is_super_admin', 'true', false)");
    }
}
