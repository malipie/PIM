<?php

declare(strict_types=1);

namespace App\Tests\Architecture\PHPStan\Fixtures;

use Doctrine\DBAL\Connection;

/**
 * Fixture for {@see \App\PHPStan\Rules\RawTenantGucRule} (#2978).
 *
 * Reading the value back is harmless — diagnostics and the RLS policies
 * themselves do it. Flagging it would make the rule noise.
 */
final class GoodServiceReadingTenantGuc
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function currentTenant(): mixed
    {
        return $this->connection->fetchOne("SELECT current_setting('app.current_tenant', true)");
    }
}
