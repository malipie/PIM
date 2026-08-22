<?php

declare(strict_types=1);

namespace App\Tests\Architecture\PHPStan\Fixtures;

use Doctrine\DBAL\Connection;

/**
 * Fixture for {@see \App\PHPStan\Rules\RawTenantGucRule} (#2978).
 *
 * An unrelated session variable is none of this rule's business.
 */
final class GoodServiceWritingUnrelatedSetting
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function pinTimeout(): void
    {
        $this->connection->executeStatement("SELECT set_config('statement_timeout', '5s', false)");
    }
}
