<?php

declare(strict_types=1);

namespace App\Tests\Architecture\PHPStan\Fixtures;

use Doctrine\DBAL\Connection;

/**
 * Fixtures for {@see \App\PHPStan\Rules\RawTenantGucRule} (#2978).
 *
 * They live under `App\Tests\` but are deliberately not `…Test` classes: the
 * rule exempts real test cases (which assert ON the RLS boundary and must be
 * able to write the GUC) and nothing else, so these fixtures are judged like
 * production code.
 */
final class BadServiceWritingTenantGuc
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** The defect: a hand-rolled copy of the boundary statement. */
    public function bind(string $tenantId): void
    {
        $this->connection->executeStatement(
            "SELECT set_config('app.current_tenant', :tenant_id, false)",
            ['tenant_id' => $tenantId],
        );
    }
}

/** The bypass flag is the same boundary seen from the other side. */
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

/**
 * Reading the value back is harmless — diagnostics and assertions do it, and
 * flagging it would make the rule noise.
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

/** An unrelated session variable is none of this rule's business. */
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
