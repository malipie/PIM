<?php

declare(strict_types=1);

namespace App\Tests\Integration\Integration\Generic;

use App\Catalog\Contracts\Integration\InboundRecordWriter;
use App\Catalog\Contracts\Integration\OutboundRecordReader;
use App\Catalog\Contracts\Integration\OutboundResultWriter;
use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\ObjectKind;
use App\Integration\Generic\Application\Sync\CursorManager;
use App\Integration\Generic\Application\Sync\InboundSyncRunner;
use App\Integration\Generic\Application\Sync\OutboundBodyEncoder;
use App\Integration\Generic\Application\Sync\OutboundSyncRunner;
use App\Integration\Generic\Application\Sync\PayloadBuilder;
use App\Integration\Generic\Application\Sync\RecordMapper;
use App\Integration\Generic\Application\Sync\SyncRunScope;
use App\Integration\Generic\Domain\Entity\Connection;
use App\Integration\Generic\Domain\Entity\FieldMapping;
use App\Integration\Generic\Domain\Entity\RemoteEndpoint;
use App\Integration\Generic\Domain\Entity\SyncBinding;
use App\Integration\Generic\Domain\Enum\MappingDirection;
use App\Integration\Generic\Domain\Enum\RemoteEndpointRole;
use App\Integration\Generic\Domain\Enum\SyncDirection;
use App\Integration\Generic\Domain\GenericRestResponse;
use App\Integration\Generic\Domain\Repository\FieldMappingRepositoryInterface;
use App\Integration\Generic\Domain\Repository\SyncRunRepositoryInterface;
use App\Integration\Generic\Infrastructure\Http\Pagination\PaginatedFetcher;
use App\Integration\Generic\Infrastructure\Http\Pagination\PaginationStrategies;
use App\Integration\Generic\Infrastructure\Http\RecordSelector;
use App\Integration\Generic\Infrastructure\Http\RemoteRequester;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Tests\Unit\Integration\Generic\Application\Sync\RecordingSleeper;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use const JSON_THROW_ON_ERROR;

/**
 * APIC-P5-04 — memory gate for the consumer sync engine. Drives an inbound pull
 * and an outbound push of N records through the real runners on the test
 * Postgres and asserts the worker stays under the 256 MiB FrankenPHP budget.
 *
 * Both runners now clear the Doctrine unit of work between batches (inbound: per
 * fetched page; outbound: every {@see OutboundSyncRunner::CLEAR_EVERY} records),
 * restoring the per-page hygiene the {@see PaginatedFetcher} docblock promised —
 * the per-record SyncRunLog rows and the upserted catalog objects no longer pin
 * the whole run in the identity map, so memory is O(batch), not O(record).
 *
 * Known residual (documented, not gated here): the outbound read seam
 * ({@see \App\Export\Application\Integration\ExportOutboundRecordReader}) still
 * materialises the full object set per run and N+1s each object's values —
 * keyset paging + a batched value fetch are the Export-context follow-up its own
 * docblock flags. See `docs/perf/sync-engine-benchmark.md`.
 *
 * Row count defaults to 2 000 (proves the flat profile while keeping the CI step
 * quick); override with `SYNC_BENCH_ROWS`. Runs only in the dedicated
 * `import-benchmark` CI step (excluded from the default suite via
 * phpunit.dist.xml <groups>).
 */
#[Group('import-benchmark')]
final class SyncMemoryBenchmarkTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private const int THRESHOLD_BYTES = 256 * 1024 * 1024;
    private const int PAGE_SIZE = 500;

    private Tenant $tenant;
    private ObjectType $productType;
    private Attribute $sku;
    private Attribute $name;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $em = $this->em();
        $this->tenant = new Tenant('bench', 'Bench Tenant');
        $em->persist($this->tenant);
        $em->flush();
        $this->tenantContext()->set($this->tenant);

        $this->productType = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $em->persist($this->productType);
        $this->sku = new Attribute('sku', ['en' => 'SKU'], AttributeType::Text);
        $this->name = new Attribute('name', ['en' => 'Name'], AttributeType::Text);
        $em->persist($this->sku);
        $em->persist($this->name);
        $em->flush();
    }

    #[Test]
    public function inboundPullStaysUnderWorkerMemoryBudget(): void
    {
        $count = $this->benchRows();
        $binding = $this->seedInboundBinding();

        $runner = new InboundSyncRunner(
            self::getContainer()->get(FieldMappingRepositoryInterface::class),
            new RecordMapper(new RecordSelector()),
            $this->paginatedFetcher($this->syntheticRemote($count)),
            self::getContainer()->get(CursorManager::class),
            new RecordSelector(),
            self::getContainer()->get(InboundRecordWriter::class),
            self::getContainer()->get(SyncRunRepositoryInterface::class),
            $this->em(),
            $this->tenantContext(),
            $this->managerRegistry(),
            new SyncRunScope(),
        );

        \memory_reset_peak_usage();
        $before = \memory_get_usage(true);
        $run = $runner->run($binding);
        $peak = \memory_get_peak_usage(true);

        self::assertSame($count, $run->getCreatedCount(), 'every pulled record upserted');
        $this->assertBounded('inbound', $peak, $before, $count);
    }

    #[Test]
    public function outboundPushStaysUnderWorkerMemoryBudget(): void
    {
        $count = $this->benchRows();
        $this->seedCatalogObjects($count);
        $binding = $this->seedOutboundBinding();

        $runner = new OutboundSyncRunner(
            self::getContainer()->get(FieldMappingRepositoryInterface::class),
            new PayloadBuilder(),
            new OutboundBodyEncoder(),
            self::getContainer()->get(OutboundRecordReader::class),
            $this->acceptingRemote(),
            self::getContainer()->get(SyncRunRepositoryInterface::class),
            $this->em(),
            $this->tenantContext(),
            self::getContainer()->get(OutboundResultWriter::class),
            new RecordSelector(),
            new SyncRunScope(),
            new RecordingSleeper(),
            $this->managerRegistry(),
        );

        \memory_reset_peak_usage();
        $before = \memory_get_usage(true);
        $run = $runner->run($binding);
        $peak = \memory_get_peak_usage(true);

        self::assertSame($count, $run->getCreatedCount(), 'every object pushed');
        $this->assertBounded('outbound', $peak, $before, $count);
    }

    private function assertBounded(string $leg, int $peak, int $before, int $count): void
    {
        self::assertLessThan(
            self::THRESHOLD_BYTES,
            $peak,
            \sprintf('%s sync peaked at %0.1f MiB for %d records, must stay under 256 MiB', $leg, $peak / 1024 / 1024, $count),
        );
        // O(batch), not O(record): the delta over the pre-run baseline must stay
        // well under the budget even as the row count scales.
        self::assertLessThan(
            200 * 1024 * 1024,
            $peak - $before,
            \sprintf('%s sync memory delta %0.1f MiB must stay bounded', $leg, ($peak - $before) / 1024 / 1024),
        );
    }

    private function benchRows(): int
    {
        $env = getenv('SYNC_BENCH_ROWS');

        return \is_string($env) && '' !== $env ? max(1, (int) $env) : 2000;
    }

    /**
     * A paginated remote that synthesises one page at a time from the requested
     * offset — O(page) memory, never the full corpus, so it measures the runner
     * and not the fixture.
     */
    private function syntheticRemote(int $total): RemoteRequester
    {
        return new class($total) implements RemoteRequester {
            public function __construct(private readonly int $total)
            {
            }

            public function request(
                Connection $connection,
                string $method,
                string $url,
                array $query = [],
                array $headers = [],
                ?string $body = null,
            ): GenericRestResponse {
                $offset = (int) ($query['offset'] ?? 0);
                $limit = (int) ($query['limit'] ?? 500);
                $results = [];
                for ($i = $offset; $i < min($offset + $limit, $this->total); ++$i) {
                    $results[] = ['sku' => 'BENCH-'.$i, 'name' => 'Bench Product '.$i];
                }

                $payload = json_encode(['results' => $results], JSON_THROW_ON_ERROR);

                return new GenericRestResponse(200, [], $payload, 1, \strlen($payload));
            }
        };
    }

    private function acceptingRemote(): RemoteRequester
    {
        return new class implements RemoteRequester {
            public function request(
                Connection $connection,
                string $method,
                string $url,
                array $query = [],
                array $headers = [],
                ?string $body = null,
            ): GenericRestResponse {
                return new GenericRestResponse(201, [], '{}', 1, 2);
            }
        };
    }

    private function paginatedFetcher(RemoteRequester $requester): PaginatedFetcher
    {
        return new PaginatedFetcher(
            $requester,
            new RecordSelector(),
            self::getContainer()->get(PaginationStrategies::class),
        );
    }

    private function seedInboundBinding(): SyncBinding
    {
        $em = $this->em();
        $connection = new Connection('idosell', 'IdoSell', 'https://api.example.test');
        $connection->assignTenant($this->tenant);
        $em->persist($connection);

        $endpoint = new RemoteEndpoint($connection, RemoteEndpointRole::ReadList, 'GET', '/products');
        $endpoint->assignTenant($this->tenant);
        $endpoint->setRecordSelector('$.results');
        $endpoint->setPagination(['strategy' => 'offset', 'limit' => self::PAGE_SIZE]);
        $em->persist($endpoint);

        $skuMap = new FieldMapping($connection, 'sku', '$.sku', MappingDirection::Inbound);
        $skuMap->assignTenant($this->tenant);
        $skuMap->setMatchKey(true);
        $em->persist($skuMap);
        $nameMap = new FieldMapping($connection, 'name', '$.name', MappingDirection::Inbound);
        $nameMap->assignTenant($this->tenant);
        $em->persist($nameMap);

        $binding = new SyncBinding($connection, $this->productType->getId(), SyncDirection::Inbound);
        $binding->assignTenant($this->tenant);
        $binding->setReadEndpoint($endpoint);
        $em->persist($binding);
        $em->flush();

        return $binding;
    }

    private function seedOutboundBinding(): SyncBinding
    {
        $em = $this->em();
        $connection = new Connection('idosell-out', 'IdoSell Out', 'https://api.example.test');
        $connection->assignTenant($this->tenant);
        $em->persist($connection);

        $endpoint = new RemoteEndpoint($connection, RemoteEndpointRole::WriteCreate, 'POST', '/products');
        $endpoint->assignTenant($this->tenant);
        $em->persist($endpoint);

        $skuMap = new FieldMapping($connection, 'sku', '$.sku', MappingDirection::Outbound);
        $skuMap->assignTenant($this->tenant);
        $skuMap->setMatchKey(true);
        $em->persist($skuMap);
        $nameMap = new FieldMapping($connection, 'name', '$.name', MappingDirection::Outbound);
        $nameMap->assignTenant($this->tenant);
        $em->persist($nameMap);

        $binding = new SyncBinding($connection, $this->productType->getId(), SyncDirection::Outbound);
        $binding->assignTenant($this->tenant);
        $binding->setWriteEndpoint($endpoint);
        $em->persist($binding);
        $em->flush();

        return $binding;
    }

    /**
     * Seeds N catalog objects with sku + name global values, flushing + clearing
     * in batches so the FIXTURE build is itself bounded; the measured run then
     * starts from a cleared unit of work.
     */
    private function seedCatalogObjects(int $count): void
    {
        $em = $this->em();
        $typeId = $this->productType->getId()->toRfc4122();
        $skuId = $this->sku->getId()->toRfc4122();
        $nameId = $this->name->getId()->toRfc4122();
        $tenantId = $this->tenant->getId()->toRfc4122();

        for ($i = 1; $i <= $count; ++$i) {
            $type = $em->find(ObjectType::class, $typeId);
            $skuAttr = $em->find(Attribute::class, $skuId);
            $nameAttr = $em->find(Attribute::class, $nameId);
            \assert($type instanceof ObjectType && $skuAttr instanceof Attribute && $nameAttr instanceof Attribute);

            $object = new CatalogObject($type, 'OUT-'.$i);
            $em->persist($object);
            $em->persist(new ObjectValue($object, $skuAttr, ['value' => 'OUT-'.$i]));
            $em->persist(new ObjectValue($object, $nameAttr, ['value' => 'Out Product '.$i]));

            if (0 === $i % self::PAGE_SIZE) {
                $em->flush();
                $em->clear();
                $this->rebindTenant($tenantId);
            }
        }

        $em->flush();
        $em->clear();
        $this->rebindTenant($tenantId);
    }

    private function rebindTenant(string $tenantId): void
    {
        $tenant = $this->em()->find(Tenant::class, $tenantId);
        \assert($tenant instanceof Tenant);
        $this->tenantContext()->set($tenant);
        $this->tenant = $tenant;
        $type = $this->em()->find(ObjectType::class, $this->productType->getId()->toRfc4122());
        \assert($type instanceof ObjectType);
        $this->productType = $type;
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

    private function managerRegistry(): ManagerRegistry
    {
        return self::getContainer()->get('doctrine');
    }
}
