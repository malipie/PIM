<?php

declare(strict_types=1);

namespace App\Tests\Integration\Integration\Generic;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Contracts\Integration\OutboundRecordReader;
use App\Catalog\Contracts\Integration\OutboundResultWriter;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Provenance;
use App\Channel\Domain\Entity\Channel;
use App\Integration\Generic\Application\Handler\OutboundSyncHandler;
use App\Integration\Generic\Application\Sync\OutboundBodyEncoder;
use App\Integration\Generic\Application\Sync\OutboundSyncRunner;
use App\Integration\Generic\Application\Sync\PayloadBuilder;
use App\Integration\Generic\Application\Sync\SyncRunScope;
use App\Integration\Generic\Domain\Entity\Connection;
use App\Integration\Generic\Domain\Entity\FieldMapping;
use App\Integration\Generic\Domain\Entity\RemoteEndpoint;
use App\Integration\Generic\Domain\Entity\SyncBinding;
use App\Integration\Generic\Domain\Entity\SyncRun;
use App\Integration\Generic\Domain\Enum\MappingDirection;
use App\Integration\Generic\Domain\Enum\RemoteEndpointRole;
use App\Integration\Generic\Domain\Enum\SyncDirection;
use App\Integration\Generic\Domain\Enum\SyncRunStatus;
use App\Integration\Generic\Domain\GenericRestResponse;
use App\Integration\Generic\Domain\Message\OutboundSyncMessage;
use App\Integration\Generic\Domain\Repository\SyncBindingRepositoryInterface;
use App\Integration\Generic\Domain\Repository\SyncRunRepositoryInterface;
use App\Integration\Generic\Infrastructure\Http\RecordSelector;
use App\Integration\Generic\Infrastructure\Http\RemoteRequester;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Tests\Unit\Integration\Generic\Application\Sync\RecordingSleeper;
use App\Tests\Unit\Integration\Generic\Infrastructure\Http\Pagination\RecordingRequester;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
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
    public function capturesRemoteIdIntoConfiguredAttribute(): void
    {
        // #2636 — a successful push stores the remote id from the response into
        // the endpoint's configured PIM attribute (provenance = integration).
        $baseId = new Attribute('base_id', ['pl' => 'Base ID'], AttributeType::Text);
        $this->em()->persist($baseId);
        $this->em()->flush();

        $this->seedObject('A-1', 'Widget');
        $binding = $this->seedBinding(rpc: true);
        $endpoint = $binding->getWriteEndpoint();
        \assert($endpoint instanceof RemoteEndpoint);
        $endpoint->setResponseIdSelector('$.product_id');
        $endpoint->setResponseIdAttribute('base_id');
        $this->em()->flush();

        $requester = new RecordingRequester(default: new GenericRestResponse(
            200,
            [],
            '{"status":"SUCCESS","product_id":777}',
            1,
            2,
        ));
        $run = $this->runner($requester)->run($binding);
        $this->em()->flush();

        self::assertSame(1, $run->getCreatedCount());

        $row = $this->em()->getConnection()->fetchAssociative(
            <<<'SQL'
                SELECT ov.value->>'value' AS value, ov.provenance
                FROM object_values ov
                JOIN attributes a ON a.id = ov.attribute_id
                WHERE a.code = 'base_id'
                LIMIT 1
                SQL,
        );
        self::assertIsArray($row, 'captured remote id was not written');
        self::assertSame('777', $row['value']);
        self::assertSame('integration', $row['provenance']);

        $log = $this->em()->getConnection()->fetchOne(
            'SELECT message FROM integration_sync_run_logs WHERE run_id = :run',
            ['run' => $run->getId()->toRfc4122()],
        );
        self::assertIsString($log);
        self::assertStringContainsString('remote_id=777', $log);
    }

    #[Test]
    public function secondPushInjectsCapturedIdAndCountsUpdated(): void
    {
        // #2636 — once the id attribute carries a value, an ordinary outbound
        // mapping injects it and the push counts as an update (no duplicate).
        $baseId = new Attribute('base_id', ['pl' => 'Base ID'], AttributeType::Text);
        $this->em()->persist($baseId);
        $this->em()->flush();

        $this->seedObject('A-1', 'Widget');
        $binding = $this->seedBinding(rpc: true);
        $connection = $binding->getConnection();
        $endpoint = $binding->getWriteEndpoint();
        \assert($endpoint instanceof RemoteEndpoint);
        $endpoint->setResponseIdSelector('$.product_id');
        $endpoint->setResponseIdAttribute('base_id');
        $idMapping = new FieldMapping($connection, 'base_id', '$.parameters.product_id', MappingDirection::Outbound);
        $idMapping->assignTenant($this->tenant);
        $this->em()->persist($idMapping);
        $this->em()->flush();

        $requester = new RecordingRequester(default: new GenericRestResponse(
            200,
            [],
            '{"status":"SUCCESS","product_id":777}',
            1,
            2,
        ));
        $firstRun = $this->runner($requester)->run($binding);
        $this->em()->flush();
        self::assertSame(1, $firstRun->getCreatedCount());
        $firstFields = [];
        parse_str((string) $requester->calls[0]['body'], $firstFields);
        $firstRawParameters = $firstFields['parameters'] ?? null;
        self::assertIsString($firstRawParameters);
        $firstParameters = json_decode($firstRawParameters, true);
        self::assertIsArray($firstParameters);
        self::assertArrayNotHasKey('product_id', $firstParameters, 'first push must be a create');

        $secondRun = $this->runner($requester)->run($this->reloadBinding($binding->getId()));
        $this->em()->flush();

        self::assertSame(0, $secondRun->getCreatedCount());
        self::assertSame(1, $secondRun->getUpdatedCount());
        self::assertCount(2, $requester->calls);
        $fields = [];
        parse_str((string) $requester->calls[1]['body'], $fields);
        $rawParameters = $fields['parameters'] ?? null;
        self::assertIsString($rawParameters);
        $parameters = json_decode($rawParameters, true);
        self::assertIsArray($parameters);
        self::assertSame(777, $parameters['product_id'] ?? null, 'second push must inject the captured id');
    }

    #[Test]
    public function throttleBacksOffThenAbortsTheRun(): void
    {
        // #2644 — an in-body 2xx throttle retries with exponential sleeps and,
        // when the remote keeps throttling, aborts the run (partial) so the
        // remaining records are not burned against a blocked token.
        $this->seedObject('A-1', 'Widget');
        $this->seedObject('B-2', 'Gadget');
        $this->seedObject('C-3', 'Gizmo');
        $binding = $this->seedBinding();

        $throttle = new GenericRestResponse(
            200,
            [],
            '{"status":"ERROR","error_code":"ERROR_QUERY_LIMIT","error_message":"Query limit exceeded, token blocked until 23:10"}',
            1,
            2,
        );
        $ok = new GenericRestResponse(201, [], '{}', 1, 2);
        // Record 1 OK; record 2 throttled on every attempt (1 + 5 retries).
        $requester = new RecordingRequester(
            queue: [$ok, $throttle, $throttle, $throttle, $throttle, $throttle, $throttle],
            default: $ok,
        );
        $sleeper = new RecordingSleeper();

        $run = $this->runner($requester, $sleeper)->run($binding);
        $this->em()->flush();

        self::assertSame(SyncRunStatus::Partial, $run->getStatus());
        self::assertSame(1, $run->getCreatedCount());
        self::assertSame(1, $run->getFailedCount());
        self::assertSame([2, 4, 8, 16, 32], $sleeper->sleeps, 'exponential backoff between throttle retries');
        self::assertCount(7, $requester->calls, '1 OK + 6 throttled attempts; third record never attempted');

        $log = $this->em()->getConnection()->fetchOne(
            "SELECT message FROM integration_sync_run_logs WHERE run_id = :run AND action = 'failed'",
            ['run' => $run->getId()->toRfc4122()],
        );
        self::assertIsString($log);
        self::assertStringContainsString('Throttled by remote — run aborted', $log);
    }

    #[Test]
    public function rateLimitHintPacesPushes(): void
    {
        // #2644 — the connection's "Limit żądań" (requests/minute) is honoured:
        // after every N pushes the runner waits out the minute window.
        $this->seedObject('A-1', 'Widget');
        $this->seedObject('B-2', 'Gadget');
        $this->seedObject('C-3', 'Gizmo');
        $binding = $this->seedBinding();
        $binding->getConnection()->setRateLimitHint(2);
        $this->em()->flush();

        $requester = new RecordingRequester(default: new GenericRestResponse(201, [], '{}', 1, 2));
        $sleeper = new RecordingSleeper();
        $run = $this->runner($requester, $sleeper)->run($binding);
        $this->em()->flush();

        self::assertSame(3, $run->getCreatedCount());
        self::assertSame([60], $sleeper->sleeps, 'one window wait after the second push');
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

    #[Test]
    public function sourceChannelScopeSendsChannelValuesWithGlobalFallback(): void
    {
        // #2667 — a binding scoped to a channel pushes the channel value where
        // one exists (name) and falls back to the global one where not (sku).
        $channel = $this->seedChannel('shopify');
        $this->seedObject('A-1', 'Widget');
        $this->addNameValue('A-1', 'Widget Shopify', channelId: $channel->getId());
        $binding = $this->seedBinding();
        $binding->setSourceChannel('shopify');
        $this->em()->flush();

        $body = $this->pushOnce();
        self::assertSame('Widget Shopify', $body['name']);
        self::assertSame('A-1', $body['sku']);
    }

    #[Test]
    public function sourceLocaleScopePrefersLocaleAndFallsBackToGlobal(): void
    {
        $this->seedObject('A-1', 'Widget');
        $this->addNameValue('A-1', 'Widget EN', locale: 'en');
        $binding = $this->seedBinding();
        $binding->setSourceLocale('en');
        $this->em()->flush();

        $body = $this->pushOnce();
        self::assertSame('Widget EN', $body['name']);
        self::assertSame('A-1', $body['sku']);
    }

    #[Test]
    public function sourceScopePrecedenceIsLocaleFirst(): void
    {
        // #2667 — rank parity with the ObjectValueLocaleOverlay: the combined
        // (locale,channel) row wins outright, and with only single-dimension
        // rows the locale one beats the channel one (locale dominates).
        $channel = $this->seedChannel('shopify');
        $this->seedObject('A-1', 'Widget');
        $this->addNameValue('A-1', 'Widget EN Shopify', channelId: $channel->getId(), locale: 'en');
        $this->addNameValue('A-1', 'Widget EN', locale: 'en');
        $this->addNameValue('A-1', 'Widget Shopify', channelId: $channel->getId());
        $binding = $this->seedBinding();
        $binding->setSourceChannel('shopify');
        $binding->setSourceLocale('en');
        $this->em()->flush();

        self::assertSame('Widget EN Shopify', $this->pushOnce()['name']);

        // Remove the combined row: the locale row must now beat the channel row.
        $this->em()->createQuery(
            'DELETE FROM '.ObjectValue::class." ov WHERE ov.locale = 'en' AND ov.channelId IS NOT NULL",
        )->execute();

        self::assertSame('Widget EN', $this->pushOnce()['name']);
    }

    #[Test]
    public function nullSourceScopeStillReadsOnlyGlobalValues(): void
    {
        // #2667 regression guard — an unscoped binding must behave exactly as
        // before: scoped rows exist but only the global values are pushed.
        $channel = $this->seedChannel('shopify');
        $this->seedObject('A-1', 'Widget');
        $this->addNameValue('A-1', 'Widget Shopify', channelId: $channel->getId());
        $this->addNameValue('A-1', 'Widget EN', locale: 'en');
        $binding = $this->seedBinding();

        $body = $this->pushOnce($binding);
        self::assertSame('Widget', $body['name']);
    }

    #[Test]
    public function unknownSourceChannelFailsTheRunBeforeAnyPush(): void
    {
        // #2667 — a stale channel code must fail loudly instead of silently
        // pushing global values to a channel-scoped remote.
        $this->seedObject('A-1', 'Widget');
        $binding = $this->seedBinding();
        $binding->setSourceChannel('ghost');
        $this->em()->flush();

        $requester = new RecordingRequester(default: new GenericRestResponse(201, [], '{}', 1, 2));

        try {
            $this->runner($requester)->run($binding);
            self::fail('Expected the run to fail on the unresolvable source channel.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('ghost', $exception->getMessage());
        }
        self::assertCount(0, $requester->calls, 'nothing may be pushed on a broken scope reference');
    }

    #[Test]
    public function midRunThrowFinalizesTheRunAsFailed(): void
    {
        // #2722 — a run left `running` by an unexpected fault would both dangle
        // forever in the UI and let the transport retry re-push every record
        // from scratch (duplicate creates on the remote).
        $this->seedObject('A-1', 'Widget');
        $binding = $this->seedBinding();

        $requester = new class implements RemoteRequester {
            public function request(
                Connection $connection,
                string $method,
                string $url,
                array $query = [],
                array $headers = [],
                ?string $body = null,
            ): GenericRestResponse {
                throw new RuntimeException('boom — unexpected mid-run fault');
            }
        };

        try {
            $this->runner($requester)->run($binding);
            self::fail('Expected the mid-run fault to propagate to the transport.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('boom', $exception->getMessage());
        }

        $runs = $this->runs()->findByBinding($this->reloadBinding($binding->getId()));
        self::assertCount(1, $runs);
        self::assertSame(SyncRunStatus::Failed, $runs[0]->getStatus());
        self::assertNotNull($runs[0]->getFinishedAt());
    }

    #[Test]
    public function handlerSkipsWhenARunOfTheBindingIsAlreadyRunning(): void
    {
        // #2722 — a redelivered message (4h redeliver_timeout vs a long push)
        // must not start a second concurrent run of the same binding.
        $this->seedObject('A-1', 'Widget');
        $binding = $this->seedBinding();

        $active = new SyncRun($binding, SyncDirection::Outbound);
        $active->assignTenant($this->tenant);
        $this->runs()->save($active);

        $requester = new RecordingRequester(default: new GenericRestResponse(201, [], '{}', 1, 2));
        $handler = new OutboundSyncHandler(
            self::getContainer()->get(SyncBindingRepositoryInterface::class),
            $this->runs(),
            $this->runner($requester),
        );
        $handler(new OutboundSyncMessage($binding->getId(), $this->tenant->getId()));

        self::assertCount(0, $requester->calls, 'the guarded handler must not push anything');
        self::assertCount(1, $this->runs()->findByBinding($binding), 'no second run may be created');
    }

    private function runs(): SyncRunRepositoryInterface
    {
        return self::getContainer()->get(SyncRunRepositoryInterface::class);
    }

    private function seedChannel(string $code): Channel
    {
        $channel = new Channel($code, ucfirst($code));
        $channel->assignTenant($this->tenant);
        $this->em()->persist($channel);
        $this->em()->flush();

        return $channel;
    }

    private function addNameValue(string $sku, string $name, ?\Symfony\Component\Uid\Uuid $channelId = null, ?string $locale = null): void
    {
        $em = $this->em();
        $object = $em->getRepository(CatalogObject::class)->findOneBy(['code' => $sku]);
        \assert($object instanceof CatalogObject);
        $em->persist(new ObjectValue($object, $this->name, ['value' => $name], Provenance::Manual, $channelId, $locale));
        $em->flush();
    }

    /**
     * Runs the binding against a recording requester and returns the single
     * pushed JSON body.
     *
     * @return array<array-key, mixed>
     */
    private function pushOnce(?SyncBinding $binding = null): array
    {
        $binding ??= $this->em()->getRepository(SyncBinding::class)->findOneBy([]);
        \assert($binding instanceof SyncBinding);

        $requester = new RecordingRequester(default: new GenericRestResponse(201, [], '{}', 1, 2));
        $run = $this->runner($requester)->run($binding);
        $this->em()->flush();

        self::assertSame(SyncRunStatus::Success, $run->getStatus());
        self::assertCount(1, $requester->calls);
        $body = json_decode((string) $requester->calls[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    private function reloadBinding(\Symfony\Component\Uid\Uuid $id): SyncBinding
    {
        $binding = $this->em()->find(SyncBinding::class, $id->toRfc4122());
        \assert($binding instanceof SyncBinding);

        return $binding;
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

    private function runner(RemoteRequester $requester, ?RecordingSleeper $sleeper = null): OutboundSyncRunner
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
            self::getContainer()->get(OutboundResultWriter::class),
            new RecordSelector(),
            new SyncRunScope(),
            $sleeper ?? new RecordingSleeper(),
            $this->managerRegistry(),
        );
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    private function managerRegistry(): ManagerRegistry
    {
        return self::getContainer()->get('doctrine');
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
