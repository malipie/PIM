<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Application\Tool\AgentToolContext;
use App\Agent\Application\Tool\CompletenessReportTool;
use App\Catalog\Contracts\Query\CompletenessReportPort;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * AGENT-P2-03 (#1960) — the report aggregates the REAL completeness
 * scoring against Postgres: averages, below-threshold counts and
 * per-required-attribute missing counts are tenant-scoped (a foreign
 * tenant's objects never leak into the numbers).
 */
final class CompletenessReportToolTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function reportAggregatesCompletenessAndMissingRequired(): void
    {
        [$tenant, $em] = $this->fixture();

        $type = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $type->updateCompletenessRules(['required' => ['name', 'ean']]);
        $em->persist($type);

        $withEverything = new CatalogObject($type, 'FULL-1');
        $withEverything->updateAttributeIndex(['name' => ['value' => 'Full'], 'ean' => ['value' => '590']]);
        $withEverything->recordCompleteness(['global' => 100]);
        $em->persist($withEverything);

        $missingEan = new CatalogObject($type, 'HALF-1');
        $missingEan->updateAttributeIndex(['name' => ['value' => 'Half']]);
        $missingEan->recordCompleteness(['global' => 50]);
        $em->persist($missingEan);

        $missingBoth = new CatalogObject($type, 'EMPTY-1');
        $missingBoth->recordCompleteness(['global' => 0]);
        $em->persist($missingBoth);
        $em->flush();

        $tool = new CompletenessReportTool($this->port());
        $result = $tool->execute(['threshold_pct' => 90], new AgentToolContext(Uuid::v7(), $tenant));

        self::assertSame(3, $result['total_objects']);
        self::assertSame(50, $result['average_pct']);
        self::assertSame(2, $result['below_threshold_count']);
        self::assertSame(
            [
                ['code' => 'ean', 'missing_count' => 2],
                ['code' => 'name', 'missing_count' => 1],
            ],
            $result['missing_required'],
        );
    }

    #[Test]
    public function foreignTenantObjectsNeverLeakIntoTheNumbers(): void
    {
        [$alpha, $em] = $this->fixture();

        $type = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $type->updateCompletenessRules(['required' => ['name']]);
        $em->persist($type);
        $mine = new CatalogObject($type, 'MINE-1');
        $mine->recordCompleteness(['global' => 0]);
        $em->persist($mine);
        $em->flush();

        // Beta tenant gets its own object type + object with the same code.
        $beta = new Tenant('beta', 'Beta Tenant');
        $em->persist($beta);
        $em->flush();
        self::getContainer()->get(TenantContext::class)->set($beta);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();
        $betaType = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $betaType->updateCompletenessRules(['required' => ['name']]);
        $em->persist($betaType);
        for ($i = 0; $i < 5; ++$i) {
            $obj = new CatalogObject($betaType, 'BETA-'.$i);
            $obj->recordCompleteness(['global' => 0]);
            $em->persist($obj);
        }
        $em->flush();
        $em->clear();

        // Back to alpha: the report must see exactly ONE object.
        self::getContainer()->get(TenantContext::class)->set($alpha);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();
        $report = $this->port()->report('product');

        self::assertSame(1, $report->totalObjects, 'foreign tenant objects must not be counted');
        self::assertSame([['code' => 'name', 'missing_count' => 1]], $report->missingRequired);
    }

    #[Test]
    public function unknownObjectTypeIsACallerError(): void
    {
        $this->fixture();

        $this->expectException(InvalidArgumentException::class);
        $this->port()->report('starship');
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

    private function port(): CompletenessReportPort
    {
        return self::getContainer()->get(CompletenessReportPort::class);
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
