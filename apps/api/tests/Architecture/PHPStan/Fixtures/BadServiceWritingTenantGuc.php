<?php

declare(strict_types=1);

namespace App\Tests\Architecture\PHPStan\Fixtures;

use Doctrine\DBAL\Connection;

/**
 * Fixture for {@see \App\PHPStan\Rules\RawTenantGucRule} (#2978).
 *
 * The defect: a hand-rolled copy of the statement that IS the tenant
 * isolation boundary. Every copy this rule replaced had drifted from the
 * original — none re-applied the Doctrine tenant filter.
 */
final class BadServiceWritingTenantGuc
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function bind(string $tenantId): void
    {
        $this->connection->executeStatement(
            "SELECT set_config('app.current_tenant', :tenant_id, false)",
            ['tenant_id' => $tenantId],
        );
    }
}
