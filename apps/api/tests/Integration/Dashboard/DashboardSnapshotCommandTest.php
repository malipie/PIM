<?php

declare(strict_types=1);

namespace App\Tests\Integration\Dashboard;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * DASH-05 (#2257) — `pim:dashboard:snapshot` upserts one row per active
 * tenant per day with the same aggregates the summary endpoint serves:
 * idempotent re-runs, per-channel JSONB payload, soft-deleted tenants
 * skipped, `--dry-run` writes nothing.
 */
final class DashboardSnapshotCommandTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function upsertsOneRowPerActiveTenantAndStaysIdempotent(): void
    {
        $em = $this->em();

        $alpha = $this->tenantWithProducts($em, 'alpha', [
            ['sku' => 'A-1', 'pct' => 100, 'per_channel' => ['shopify' => 100]],
            ['sku' => 'A-2', 'pct' => 40, 'per_channel' => ['shopify' => 20]],
        ]);
        $this->tenantWithProducts($em, 'beta', [
            ['sku' => 'B-1', 'pct' => 0, 'per_channel' => []],
        ]);

        $gamma = new Tenant('gamma', 'Gamma Tenant');
        $gamma->softDelete();
        $em->persist($gamma);
        $em->flush();

        $tester = $this->tester();
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $rows = $em->getConnection()->fetchAllAssociative(
            'SELECT t.code, s.products_total, s.publish_ready_count, s.avg_completeness_pct, s.per_channel '
            .'FROM dashboard_snapshots s JOIN tenants t ON t.id = s.tenant_id ORDER BY t.code',
        );

        self::assertCount(2, $rows, 'one row per ACTIVE tenant (gamma is soft-deleted)');
        self::assertSame('alpha', $rows[0]['code']);
        self::assertSame(2, $this->intOf($rows[0]['products_total']));
        self::assertSame(1, $this->intOf($rows[0]['publish_ready_count']));
        self::assertSame(70, $this->intOf($rows[0]['avg_completeness_pct']));
        self::assertSame(
            ['shopify' => ['avgPct' => 60, 'readyCount' => 1]],
            json_decode($this->strOf($rows[0]['per_channel']), true),
        );
        self::assertSame('beta', $rows[1]['code']);
        self::assertSame(1, $this->intOf($rows[1]['products_total']));
        self::assertSame(0, $this->intOf($rows[1]['publish_ready_count']));

        // Second run on the same day: still one row per tenant (upsert),
        // values refreshed after alpha gains a product.
        $this->addProduct($em, $alpha, 'A-3', 100);
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $after = $em->getConnection()->fetchAllAssociative(
            'SELECT t.code, s.products_total FROM dashboard_snapshots s '
            .'JOIN tenants t ON t.id = s.tenant_id ORDER BY t.code',
        );
        self::assertCount(2, $after, 'idempotent per (tenant, date)');
        self::assertSame(3, $this->intOf($after[0]['products_total']), 'upsert refreshed the aggregates');
    }

    #[Test]
    public function dryRunComputesButWritesNothing(): void
    {
        $em = $this->em();
        $this->tenantWithProducts($em, 'alpha', [['sku' => 'A-1', 'pct' => 50, 'per_channel' => []]]);

        $tester = $this->tester();
        $tester->execute(['--dry-run' => true]);
        $tester->assertCommandIsSuccessful();

        self::assertStringContainsString('[dry-run] alpha: total=1', $tester->getDisplay());
        self::assertSame(
            0,
            $this->intOf($em->getConnection()->fetchOne('SELECT COUNT(*) FROM dashboard_snapshots')),
            'dry-run must not write',
        );
    }

    private function intOf(mixed $value): int
    {
        return (int) (\is_scalar($value) ? $value : 0);
    }

    private function strOf(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param list<array{sku: string, pct: int, per_channel: array<string, int>}> $products
     */
    private function tenantWithProducts(EntityManagerInterface $em, string $code, array $products): Tenant
    {
        $tenant = new Tenant($code, ucfirst($code).' Tenant');
        $em->persist($tenant);
        $em->flush();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();

        $type = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $em->persist($type);
        foreach ($products as $spec) {
            $object = new CatalogObject($type, $spec['sku']);
            $completeness = ['global' => $spec['pct']];
            if ([] !== $spec['per_channel']) {
                $completeness['per_channel'] = $spec['per_channel'];
            }
            $object->recordCompleteness($completeness);
            $em->persist($object);
        }
        $em->flush();

        return $tenant;
    }

    private function addProduct(EntityManagerInterface $em, Tenant $tenant, string $sku, int $pct): void
    {
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();
        $type = $em->getRepository(ObjectType::class)->findOneBy(['code' => 'product', 'tenant' => $tenant]);
        \assert($type instanceof ObjectType);
        $object = new CatalogObject($type, $sku);
        $object->recordCompleteness(['global' => $pct]);
        $em->persist($object);
        $em->flush();
    }

    private function tester(): CommandTester
    {
        $kernel = self::$kernel;
        \assert(null !== $kernel, 'kernel is booted by the fixture helpers');

        return new CommandTester(new Application($kernel)->find('pim:dashboard:snapshot'));
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
