<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilter;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use App\Shared\Infrastructure\Tenant\TenantScopeBinder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\FilterCollection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #2466 / #2978 — every entry point without a principal (console commands and
 * the signed, session-less routes) must establish the same three-layer tenant
 * context the HTTP/worker paths do: TenantContext, the Doctrine filter and the
 * Postgres RLS GUC. Miss one and RLS answers zero rows for `pim_app` — the
 * shape that silently no-op'd pim:search:reindex, broke pim:asset:upload, and
 * made pim:agent:start report a missing BYOK key that was there all along.
 */
final class TenantScopeBinderTest extends TestCase
{
    /** @var list<array{string, array<mixed>}> */
    private array $statements = [];

    #[Test]
    public function bindSetsContextFilterAndRlsGuc(): void
    {
        $tenant = new Tenant('demo', 'Demo');
        $context = new TenantContext();
        [$configurator, $filters] = $this->realConfigurator($context);

        $binder = new TenantScopeBinder($context, $configurator, $this->recordingConnection());
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

        $binder = new TenantScopeBinder($context, $configurator, $this->recordingConnection());
        $binder->release();

        self::assertNull($context->get(), 'release must clear the PHP-side context');
        self::assertCount(1, $this->statements);
        self::assertStringContainsString("set_config('app.current_tenant', ''", $this->statements[0][0]);
    }

    /**
     * #2978 — the signed preview route must not override an authenticated
     * caller's own scope, so it asks before binding. Reading it through the
     * binder keeps TenantContext out of the caller entirely.
     */
    #[Test]
    public function isBoundReportsWhetherATenantIsAlreadyBound(): void
    {
        $context = new TenantContext();
        [$configurator] = $this->realConfigurator($context);
        $binder = new TenantScopeBinder($context, $configurator, $this->recordingConnection());

        self::assertFalse($binder->isBound(), 'a fresh context carries no tenant');

        $binder->bind(new Tenant('demo', 'Demo'));
        self::assertTrue($binder->isBound());

        $binder->release();
        self::assertFalse($binder->isBound(), 'release must leave nothing bound');
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

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConfiguration')->willReturn($configuration);
        $filters = new FilterCollection($em);
        $em->method('getFilters')->willReturn($filters);

        return [new TenantFilterConfigurator($em, $context), $filters];
    }

    private function recordingConnection(): Connection
    {
        $this->statements = [];
        $connection = $this->createStub(Connection::class);
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
