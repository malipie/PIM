<?php

declare(strict_types=1);

namespace App\Tests\Integration\Integration\Generic;

use App\Catalog\Contracts\Integration\OutboundRecordReader;
use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\ObjectKind;
use App\Integration\Generic\Application\Sync\OutboundBodyEncoder;
use App\Integration\Generic\Application\Sync\OutboundSyncRunner;
use App\Integration\Generic\Application\Sync\PayloadBuilder;
use App\Integration\Generic\Domain\Entity\Connection;
use App\Integration\Generic\Domain\Entity\FieldMapping;
use App\Integration\Generic\Domain\Entity\RemoteEndpoint;
use App\Integration\Generic\Domain\Entity\SyncBinding;
use App\Integration\Generic\Domain\Enum\MappingDirection;
use App\Integration\Generic\Domain\Enum\RemoteEndpointRole;
use App\Integration\Generic\Domain\Enum\SyncDirection;
use App\Integration\Generic\Domain\Enum\SyncRunStatus;
use App\Integration\Generic\Domain\GenericRestResponse;
use App\Integration\Generic\Domain\Repository\SyncRunRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Tests\Unit\Integration\Generic\Infrastructure\Http\Pagination\RecordingRequester;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use const JSON_THROW_ON_ERROR;

/**
 * APIC-P3-06 — end-to-end outbound sync: real catalog objects are serialised by
 * the Export-engine reader, mapped to a 1:1 push body and POSTed to the write
 * endpoint (push captured by a fake requester). Offline + deterministic.
 */
final class OutboundSyncRunnerTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private Tenant $tenant;
    private ObjectType $productType;
    private Attribute $sku;
    private Attribute $name;

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
        $this->sku = new Attribute('sku', ['pl' => 'SKU'], AttributeType::Text);
        $this->name = new Attribute('name', ['pl' => 'Nazwa'], AttributeType::Text);
        $em->persist($this->sku);
        $em->persist($this->name);
        $em->flush();
    }

    #[Test]
    public function pushesEachObjectAsAMappedBody(): void
    {
        $this->seedObject('A-1', 'Widget');
        $this->seedObject('B-2', 'Gadget');
        $binding = $this->seedBinding();

        $requester = new RecordingRequester(default: new GenericRestResponse(201, [], '{}', 1, 2));
        $run = $this->runner($requester)->run($binding);
        $this->em()->flush();

        self::assertSame(SyncRunStatus::Success, $run->getStatus());
        self::assertSame(2, $run->getCreatedCount());
        self::assertCount(2, $requester->calls);

        // Each push is a POST to the write endpoint with a {sku,name} body.
        $skus = [];
        foreach ($requester->calls as $call) {
            self::assertSame('POST', $call['method']);
            self::assertSame('https://api.example.com/products', $call['url']);
            $body = json_decode((string) $call['body'], true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertArrayHasKey('sku', $body);
            self::assertArrayHasKey('name', $body);
            $skus[] = $body['sku'];
        }
        sort($skus);
        self::assertSame(['A-1', 'B-2'], $skus);
    }

    #[Test]
    public function outboundFilterPushesOnlyMatchingObjects(): void
    {
        // #2549 — a binding scoped to `status = published` pushes only the
        // published object; the draft is never sent to the remote.
        $this->seedObject('PUB-1', 'Published', CatalogObject::STATUS_PUBLISHED);
        $this->seedObject('DRAFT-1', 'Draft');
        $binding = $this->seedBinding();
        $binding->setOutboundFilter(['attr' => 'status', 'op' => '=', 'value' => 'published']);
        $this->em()->flush();

        $requester = new RecordingRequester(default: new GenericRestResponse(201, [], '{}', 1, 2));
        $run = $this->runner($requester)->run($binding);
        $this->em()->flush();

        self::assertSame(SyncRunStatus::Success, $run->getStatus());
        self::assertSame(1, $run->getCreatedCount());
        self::assertCount(1, $requester->calls);
        $body = json_decode((string) $requester->calls[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('PUB-1', $body['sku']);
    }

    #[Test]
    public function dryRunBuildsPayloadsWithoutCallingRemote(): void
    {
        $this->seedObject('A-1', 'Widget');
        $this->seedObject('B-2', 'Gadget');
        $binding = $this->seedBinding();

        $requester = new RecordingRequester(default: new GenericRestResponse(201, [], '{}', 1, 2));
        $run = $this->runner($requester)->run($binding, true); // dry run
        $this->em()->flush();

        self::assertCount(0, $requester->calls, 'dry run must not call the remote');
        self::assertSame(0, $run->getCreatedCount());
        self::assertSame(2, $run->getSkippedCount());
        self::assertSame(SyncRunStatus::Success, $run->getStatus());
    }

    #[Test]
    public function formEndpointSendsUrlencodedRpcEnvelope(): void
    {
        // #2634 — BaseLinker-style push: the endpoint's static envelope carries
        // the RPC method + inventory id, mappings fill $.parameters.*, and the
        // wire format is form-urlencoded with `parameters` as a JSON string.
        $this->seedObject('A-1', 'Widget');
        $binding = $this->seedBinding(rpc: true);

        $requester = new RecordingRequester(default: new GenericRestResponse(200, [], '{"status":"SUCCESS","product_id":7}', 1, 2));
        $run = $this->runner($requester)->run($binding);
        $this->em()->flush();

        self::assertSame(SyncRunStatus::Success, $run->getStatus());
        self::assertCount(1, $requester->calls);
        $call = $requester->calls[0];
        self::assertSame('https://api.example.com/connector.php', $call['url']);
        self::assertSame('application/x-www-form-urlencoded', $call['headers']['Content-Type'] ?? null);

        parse_str((string) $call['body'], $fields);
        self::assertSame('addInventoryProduct', $fields['method'] ?? null);
        $rawParameters = $fields['parameters'] ?? null;
        self::assertIsString($rawParameters);
        $parameters = json_decode($rawParameters, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($parameters);
        self::assertSame(1234, $parameters['inventory_id'] ?? null);
        self::assertSame('A-1', $parameters['sku'] ?? null);
        self::assertSame(['name' => 'Widget'], $parameters['text_fields'] ?? null);
    }

    #[Test]
    public function endpointScopedMappingIsExcludedFromOtherOperations(): void
    {
        // #2634 — a mapping scoped to another write endpoint (a different RPC
        // operation) must not leak into this endpoint's payload.
        $this->seedObject('A-1', 'Widget');
        $binding = $this->seedBinding();

        $em = $this->em();
        $connection = $binding->getConnection();
        $otherEndpoint = new RemoteEndpoint($connection, RemoteEndpointRole::WriteUpdate, 'POST', '/connector.php');
        $otherEndpoint->assignTenant($this->tenant);
        $em->persist($otherEndpoint);
        $scoped = new FieldMapping($connection, 'name', '$.products.name', MappingDirection::Outbound);
        $scoped->assignTenant($this->tenant);
        $scoped->setEndpoint($otherEndpoint);
        $em->persist($scoped);
        $em->flush();

        $requester = new RecordingRequester(default: new GenericRestResponse(201, [], '{}', 1, 2));
        $this->runner($requester)->run($binding);
        $this->em()->flush();

        self::assertCount(1, $requester->calls);
        $body = json_decode((string) $requester->calls[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertArrayNotHasKey('products', $body, 'mapping scoped to another endpoint leaked into the push');
        self::assertSame('A-1', $body['sku']);
    }

    #[Test]
    public function rpcErrorStatusInTwoHundredCountsAsFailed(): void
    {
        // #2634 — BaseLinker answers HTTP 200 with {"status":"ERROR",…}; the
        // record must be classified failed, not created.
        $this->seedObject('A-1', 'Widget');
        $binding = $this->seedBinding();

        $requester = new RecordingRequester(default: new GenericRestResponse(
            200,
            [],
            '{"status":"ERROR","error_code":"ERROR_AUTH_TOKEN","error_message":"Invalid token"}',
            1,
            2,
        ));
        $run = $this->runner($requester)->run($binding);
        $this->em()->flush();

        self::assertSame(0, $run->getCreatedCount());
        self::assertSame(1, $run->getFailedCount());
    }

    private function seedObject(string $sku, string $name, ?string $status = null): void
    {
        $em = $this->em();
        $object = new CatalogObject($this->productType, $sku);
        if (null !== $status) {
            $object->forceStatus($status);
        }
        $em->persist($object);
        $em->persist(new ObjectValue($object, $this->sku, ['value' => $sku]));
        $em->persist(new ObjectValue($object, $this->name, ['value' => $name]));
        $em->flush();
    }

    private function seedBinding(bool $rpc = false): SyncBinding
    {
        $em = $this->em();
        $connection = new Connection('idosell', 'IdoSell', 'https://api.example.com');
        $connection->assignTenant($this->tenant);
        $em->persist($connection);

        $writeEndpoint = new RemoteEndpoint(
            $connection,
            RemoteEndpointRole::WriteCreate,
            'POST',
            $rpc ? '/connector.php' : '/products',
        );
        $writeEndpoint->assignTenant($this->tenant);
        if ($rpc) {
            $writeEndpoint->setRequestFormat('form');
            $writeEndpoint->setRequestBodyTemplate([
                'method' => 'addInventoryProduct',
                'parameters' => ['inventory_id' => 1234],
            ]);
        }
        $em->persist($writeEndpoint);

        $skuMapping = new FieldMapping(
            $connection,
            'sku',
            $rpc ? '$.parameters.sku' : '$.sku',
            MappingDirection::Outbound,
        );
        $skuMapping->assignTenant($this->tenant);
        $skuMapping->setMatchKey(true);
        $em->persist($skuMapping);
        $nameMapping = new FieldMapping(
            $connection,
            'name',
            $rpc ? '$.parameters.text_fields.name' : '$.name',
            MappingDirection::Outbound,
        );
        $nameMapping->assignTenant($this->tenant);
        $em->persist($nameMapping);

        $binding = new SyncBinding($connection, $this->productType->getId(), SyncDirection::Outbound);
        $binding->assignTenant($this->tenant);
        $binding->setWriteEndpoint($writeEndpoint);
        $em->persist($binding);
        $em->flush();

        return $binding;
    }

    private function runner(RecordingRequester $requester): OutboundSyncRunner
    {
        return new OutboundSyncRunner(
            self::getContainer()->get(\App\Integration\Generic\Domain\Repository\FieldMappingRepositoryInterface::class),
            new PayloadBuilder(),
            new OutboundBodyEncoder(),
            self::getContainer()->get(OutboundRecordReader::class),
            $requester,
            self::getContainer()->get(SyncRunRepositoryInterface::class),
            $this->em(),
            $this->tenantContext(),
        );
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
