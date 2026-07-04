<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\Query\SqlChannelCompletenessReport;
use App\Catalog\Contracts\Query\ChannelCompletenessPort;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Channel\Domain\Entity\Channel;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * DASH-03 (#2253) — the per-channel aggregate reads the REAL
 * `completeness->'per_channel'` JSONB against Postgres: averages and
 * publish-ready counts per channel, worst-first order, channel-name
 * resolution with a code fallback, product-kind scope and tenant
 * isolation.
 */
final class ChannelCompletenessReportTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function aggregatesPerChannelWorstFirstWithNameResolution(): void
    {
        [, $em] = $this->fixture();

        $em->persist(new Channel('shopify', 'Shopify'));
        $em->persist(new Channel('google', 'Google Shopping'));

        $type = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $em->persist($type);

        $p1 = new CatalogObject($type, 'P-1');
        $p1->recordCompleteness(['global' => 80, 'per_channel' => ['shopify' => 90, 'google' => 40]]);
        $em->persist($p1);

        $p2 = new CatalogObject($type, 'P-2');
        $p2->recordCompleteness(['global' => 70, 'per_channel' => ['shopify' => 70, 'google' => 60]]);
        $em->persist($p2);

        $p3 = new CatalogObject($type, 'P-3');
        $p3->recordCompleteness([
            'global' => 100,
            'per_channel' => ['shopify' => 100, 'allegro' => 10],
        ]);
        $em->persist($p3);

        // Legacy object without per_channel — must simply not contribute.
        $legacy = new CatalogObject($type, 'P-LEGACY');
        $legacy->recordCompleteness(['global' => 50]);
        $em->persist($legacy);

        // Non-product kinds stay out of the SKU-focused aggregate.
        $assetType = new ObjectType('asset', ObjectKind::Asset, ['en' => 'Asset']);
        $em->persist($assetType);
        $asset = new CatalogObject($assetType, 'A-1');
        $asset->recordCompleteness(['global' => 0, 'per_channel' => ['shopify' => 0]]);
        $em->persist($asset);

        $em->flush();

        $report = $this->port()->perChannel();

        self::assertSame(
            ['allegro', 'google', 'shopify'],
            array_map(static fn ($row) => $row->channelCode, $report),
            'worst average first',
        );

        [$allegro, $google, $shopify] = $report;

        // 'allegro' has no channels row — the code doubles as the name.
        self::assertSame('allegro', $allegro->channelName);
        self::assertSame(10, $allegro->avgPct);
        self::assertSame(0, $allegro->readyCount);

        self::assertSame('Google Shopping', $google->channelName);
        self::assertSame(50, $google->avgPct);
        self::assertSame(0, $google->readyCount);

        self::assertSame('Shopify', $shopify->channelName);
        self::assertSame(87, $shopify->avgPct, '(90+70+100)/3 rounded');
        self::assertSame(2, $shopify->readyCount, 'two products at/above 80');
    }

    #[Test]
    public function thresholdParameterDrivesTheReadyCount(): void
    {
        [, $em] = $this->fixture();

        $type = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $em->persist($type);
        $p1 = new CatalogObject($type, 'P-1');
        $p1->recordCompleteness(['global' => 60, 'per_channel' => ['shopify' => 60]]);
        $em->persist($p1);
        $em->flush();

        self::assertSame(0, $this->port()->perChannel(80)[0]->readyCount);
        self::assertSame(1, $this->port()->perChannel(50)[0]->readyCount);
    }

    #[Test]
    public function foreignTenantObjectsNeverLeakIntoTheNumbers(): void
    {
        [$alpha, $em] = $this->fixture();

        $type = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $em->persist($type);
        $mine = new CatalogObject($type, 'MINE-1');
        $mine->recordCompleteness(['global' => 100, 'per_channel' => ['shopify' => 100]]);
        $em->persist($mine);
        $em->flush();

        $beta = new Tenant('beta', 'Beta Tenant');
        $em->persist($beta);
        $em->flush();
        self::getContainer()->get(TenantContext::class)->set($beta);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();
        $betaType = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $em->persist($betaType);
        for ($i = 0; $i < 5; ++$i) {
            $obj = new CatalogObject($betaType, 'BETA-'.$i);
            $obj->recordCompleteness(['global' => 0, 'per_channel' => ['shopify' => 0]]);
            $em->persist($obj);
        }
        $em->flush();
        $em->clear();

        self::getContainer()->get(TenantContext::class)->set($alpha);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();

        $report = $this->port()->perChannel();

        self::assertCount(1, $report);
        self::assertSame(100, $report[0]->avgPct, 'foreign tenant objects must not drag the average');
        self::assertSame(1, $report[0]->readyCount);
    }

    /**
     * @return array{0: Tenant, 1: EntityManagerInterface}
     */
    private function fixture(): array
    {
        $tenant = new Tenant('alpha', 'Alpha Tenant');
        $em = $this->em();
        $em->persist($tenant);
        $em->flush();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();

        return [$tenant, $em];
    }

    private function port(): ChannelCompletenessPort
    {
        // Direct construction — the container inlines/removes the unused
        // port alias until DASH-04 injects it into the dashboard endpoint.
        return new SqlChannelCompletenessReport(
            $this->em(),
            self::getContainer()->get(TenantContext::class),
        );
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
