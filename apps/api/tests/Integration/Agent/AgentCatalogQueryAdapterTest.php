<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Search\Contracts\CatalogQueryPort;
use App\Search\Contracts\CatalogQueryResult;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * AGENT-P2-01 (#1958) — the Contracts adapter goes through the SAME
 * validation/compilation pipeline as the manual list view: invalid
 * kind and invalid DSL throw caller errors (the model can correct
 * itself); a valid query returns the result shape with a boolean
 * `degraded` flag (true when the engine is unreachable, e.g. in CI).
 */
final class AgentCatalogQueryAdapterTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function unknownKindIsACallerError(): void
    {
        $this->bootWithTenant();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown object kind/');
        $this->port()->search('starship');
    }

    #[Test]
    public function invalidFilterDslIsACallerError(): void
    {
        $this->bootWithTenant();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid filter DSL/');
        $this->port()->search('product', filterDsl: ['nonsense' => true]);
    }

    #[Test]
    public function validQueryReturnsResultShape(): void
    {
        $this->bootWithTenant();

        $result = $this->port()->search('product', query: 'anything');

        self::assertInstanceOf(CatalogQueryResult::class, $result);
        self::assertGreaterThanOrEqual(0, $result->totalHits);
        // In CI (no Meilisearch service) the engine reports degraded=true;
        // on the dev stack it answers normally - both are valid shapes.
        self::assertIsBool($result->degraded);
    }

    private function bootWithTenant(): void
    {
        $tenant = new Tenant('alpha', 'Alpha Tenant');
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $em->persist($tenant);
        $em->flush();
        self::getContainer()->get(TenantContext::class)->set($tenant);
    }

    private function port(): CatalogQueryPort
    {
        return self::getContainer()->get(CatalogQueryPort::class);
    }
}
