<?php

declare(strict_types=1);

namespace App\Tests\Integration\Integration\Generic;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Contracts\Integration\InboundRecordWriter;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Integration\Generic\Application\Sync\CursorManager;
use App\Integration\Generic\Application\Sync\InboundSyncRunner;
use App\Integration\Generic\Application\Sync\RecordMapper;
use App\Integration\Generic\Domain\Entity\Connection;
use App\Integration\Generic\Domain\Entity\FieldMapping;
use App\Integration\Generic\Domain\Entity\RemoteEndpoint;
use App\Integration\Generic\Domain\Entity\SyncBinding;
use App\Integration\Generic\Domain\Enum\MappingDirection;
use App\Integration\Generic\Domain\Enum\RemoteEndpointRole;
use App\Integration\Generic\Domain\Enum\SyncRunStatus;
use App\Integration\Generic\Domain\GenericRestResponse;
use App\Integration\Generic\Domain\Repository\FieldMappingRepositoryInterface;
use App\Integration\Generic\Domain\Repository\SyncRunRepositoryInterface;
use App\Integration\Generic\Infrastructure\Http\Pagination\PaginatedFetcher;
use App\Integration\Generic\Infrastructure\Http\Pagination\PaginationStrategies;
use App\Integration\Generic\Infrastructure\Http\RecordSelector;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Tests\Unit\Integration\Generic\Infrastructure\Http\Pagination\RecordingRequester;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * APIC-P3-04 — end-to-end inbound sync: a mocked remote page flows through the
 * fetcher → record mapper → cross-BC write seam into the real catalog, and the
 * run is audited. Offline + deterministic (fake requester).
 */
final class InboundSyncRunnerTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private Tenant $tenant;
    private ObjectType $productType;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $em = $this->em();
        $this->tenant = new Tenant('demo', 'Demo');
        $em->persist($this->tenant);
        $em->flush();
        $this->tenantContext()->set($this->tenant);

        $this->productType = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt']);
        $em->persist($this->productType);
        $em->persist(new Attribute('sku', ['pl' => 'SKU'], AttributeType::Text));
        $em->persist(new Attribute('name', ['pl' => 'Nazwa'], AttributeType::Text));
        $em->flush();
    }

    #[Test]
    public function pullsAMockedPageIntoTheCatalog(): void
    {
        $binding = $this->seedBinding();

        $page = json_encode([
            'results' => [
                ['sku' => 'A-1', 'name' => 'Widget'],
                ['sku' => 'B-2', 'name' => 'Gadget'],
            ],
        ]);
        $runner = $this->runner([new GenericRestResponse(200, [], (string) $page, 1, 64)]);

        $run = $runner->run($binding);
        $this->em()->flush();

        self::assertSame(SyncRunStatus::Success, $run->getStatus());
        self::assertSame(2, $run->getCreatedCount());
        self::assertSame(0, $run->getFailedCount());

        $this->em()->clear();
        self::assertNotNull($this->repository()->findByCode('A-1', ObjectKind::Product, $this->tenant));
        self::assertNotNull($this->repository()->findByCode('B-2', ObjectKind::Product, $this->tenant));
    }

    #[Test]
    public function reRunUpdatesInsteadOfDuplicating(): void
    {
        $binding = $this->seedBinding();
        $page = static fn (string $name): string => (string) json_encode([
            'results' => [['sku' => 'A-1', 'name' => $name]],
        ]);

        $this->runner([new GenericRestResponse(200, [], $page('Widget'), 1, 32)])->run($binding);
        $this->em()->flush();
        $this->em()->clear();

        $binding = $this->em()->find(SyncBinding::class, $binding->getId()->toRfc4122());
        \assert($binding instanceof SyncBinding);
        // em->clear() detached the setUp tenant; re-point the context at the
        // managed tenant so the listener can stamp the new SyncRun.
        $boundTenant = $binding->getTenant();
        \assert($boundTenant instanceof Tenant);
        $this->tenantContext()->set($boundTenant);
        $run = $this->runner([new GenericRestResponse(200, [], $page('Widget v2'), 1, 32)])->run($binding);
        $this->em()->flush();

        self::assertSame(0, $run->getCreatedCount());
        self::assertSame(1, $run->getUpdatedCount());
    }

    private function seedBinding(): SyncBinding
    {
        $em = $this->em();
        $connection = new Connection('idosell', 'IdoSell', 'https://api.example.com');
        $connection->assignTenant($this->tenant);
        $em->persist($connection);

        $endpoint = new RemoteEndpoint($connection, RemoteEndpointRole::ReadList, 'GET', '/products');
        $endpoint->assignTenant($this->tenant);
        $endpoint->setRecordSelector('$.results');
        $em->persist($endpoint);

        $sku = new FieldMapping($connection, 'sku', '$.sku', MappingDirection::Inbound);
        $sku->assignTenant($this->tenant);
        $sku->setMatchKey(true);
        $em->persist($sku);
        $name = new FieldMapping($connection, 'name', '$.name', MappingDirection::Inbound);
        $name->assignTenant($this->tenant);
        $em->persist($name);

        $binding = new SyncBinding($connection, $this->productType->getId());
        $binding->assignTenant($this->tenant);
        $binding->setReadEndpoint($endpoint);
        $em->persist($binding);
        $em->flush();

        return $binding;
    }

    /**
     * @param list<GenericRestResponse> $responses
     */
    private function runner(array $responses): InboundSyncRunner
    {
        $selector = new RecordSelector();
        $fetcher = new PaginatedFetcher(
            new RecordingRequester($responses),
            $selector,
            self::getContainer()->get(PaginationStrategies::class),
        );

        return new InboundSyncRunner(
            self::getContainer()->get(FieldMappingRepositoryInterface::class),
            new RecordMapper($selector),
            $fetcher,
            self::getContainer()->get(CursorManager::class),
            $selector,
            self::getContainer()->get(InboundRecordWriter::class),
            self::getContainer()->get(SyncRunRepositoryInterface::class),
            $this->em(),
            $this->tenantContext(),
            $this->managerRegistry(),
        );
    }

    private function managerRegistry(): ManagerRegistry
    {
        return self::getContainer()->get('doctrine');
    }

    private function repository(): CatalogObjectRepositoryInterface
    {
        return self::getContainer()->get(CatalogObjectRepositoryInterface::class);
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
