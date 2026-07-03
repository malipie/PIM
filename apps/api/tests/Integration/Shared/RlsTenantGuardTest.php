<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\RlsTenantGuard;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * #2156 — the guard re-establishes the `app.current_tenant` GUC that RLS
 * policies read, so a write issued after a mid-request DBAL reconnect (which
 * drops the session variable) still lands under FORCE RLS instead of
 * silently affecting zero rows.
 */
final class RlsTenantGuardTest extends KernelTestCase
{
    #[Test]
    public function reassertRestoresTheTenantGucOnTheConnection(): void
    {
        self::bootKernel();
        $connection = $this->connection();
        $tenant = new Tenant('alpha', 'Alpha Tenant');

        // Simulate the drift: a reconnect leaves the session variable empty.
        $connection->executeStatement("SELECT set_config('app.current_tenant', '', false)");
        self::assertSame('', $connection->fetchOne("SELECT current_setting('app.current_tenant', true)"));

        new RlsTenantGuard($connection)->reassert($tenant);

        self::assertSame(
            $tenant->getId()->toRfc4122(),
            $connection->fetchOne("SELECT current_setting('app.current_tenant', true)"),
            'reassert must set app.current_tenant so the next RLS-protected write sees the tenant',
        );
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }
}
