<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Console\TenantConsoleBinder;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilter;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\FilterCollection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #2466 — console maintenance commands must establish the same three-layer
 * tenant context the HTTP/worker paths do (TenantContext, Doctrine filter,
 * Postgres RLS GUC), otherwise RLS answers zero rows for `pim_app` and
 * pim:search:reindex / recalculate-completeness silently process nothing.
 */
final class TenantConsoleBinderTest extends TestCase
{
    /** @var list<array{string, array<mixed>}> */
    private array $statements = [];

    #[Test]
    public function bindSetsContextFilterAndRlsGuc(): void
    {
        $tenant = new Tenant('demo', 'Demo');
        $context = new TenantContext();
        [$configurator, $filters] = $this->realConfigurator($context);

        $binder = new TenantConsoleBinder($context, $configurator, $this->recordingConnection());
        $binder->bind($tenant);

        self::assertSame($tenant, $context->get(), 'PHP-side TenantContext must carry the tenant');
        self::assertTrue($filters->isEnabled('tenant'), 'Doctrine tenant filter must be enabled');

        self::assertCount(2, $this->statements);
        self::assertStringContainsString("set_config('app.current_tenant'", $this->statements[0][0]);
        self::assertSame($tenant->getId()->toRfc4122(), $this->statements[0][1]['tenant_id'] ?? null);
        self::assertStringContainsString("set_config('app.is_super_admin', 'false'", $this->statements[1][0]);
    }

    #[Test]
    public function releaseClearsContextAndGuc(): void
    {
        $tenant = new Tenant('demo', 'Demo');
        $context = new TenantContext();
        $context->set($tenant);
        [$configurator] = $this->realConfigurator($context);

        $binder = new TenantConsoleBinder($context, $configurator, $this->recordingConnection());
        $binder->release();

        self::assertNull($context->get(), 'release must clear the PHP-side context');
        self::assertCount(1, $this->statements);
        self::assertStringContainsString("set_config('app.current_tenant', ''", $this->statements[0][0]);
    }

    /**
     * TenantFilterConfigurator is final (cannot be doubled), so back it with
     * a real FilterCollection over a mocked EntityManager — this also proves
     * the filter genuinely gets enabled + parameterised.
     *
     * @return array{0: TenantFilterConfigurator, 1: FilterCollection}
     */
    private function realConfigurator(TenantContext $context): array
    {
        $configuration = new Configuration();
        $configuration->addFilter('tenant', TenantFilter::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConfiguration')->willReturn($configuration);
        $filters = new FilterCollection($em);
        $em->method('getFilters')->willReturn($filters);

        return [new TenantFilterConfigurator($em, $context), $filters];
    }

    private function recordingConnection(): Connection
    {
        $this->statements = [];
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('executeStatement')
            ->willReturnCallback(
                /** @param array<string, mixed> $params */
                function (string $sql, array $params = []): int {
                    $this->statements[] = [$sql, $params];

                    return 1;
                },
            );

        return $connection;
    }
}
