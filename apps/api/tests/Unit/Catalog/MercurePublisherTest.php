<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Application\BulkContext;
use App\Catalog\Application\Subscriber\MercurePublisher;
use App\Catalog\Contracts\Event\ObjectAttributesChanged;
use App\Catalog\Contracts\Event\ObjectCreated;
use App\Catalog\Contracts\Event\ObjectEnabledChanged;
use App\Catalog\Contracts\Event\ObjectSubmittedForReview;
use App\Catalog\Domain\ObjectKind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

/**
 * Unit coverage for MercurePublisher topic + payload contract (#47 / 0.4.7;
 * tenant-scoped + private after AUD-001 / #1573).
 *
 * Publisher emits domain events on two **tenant-scoped, private** topics:
 *   - row-specific `<base>/tenant/<tid>/objects/<id>` so an admin editing
 *     a single row subscribes once and gets every change to that row;
 *   - broadcast `<base>/tenant/<tid>/objects` so a list view picks up
 *     creations / deletions across the tenant's catalog with one
 *     connection.
 *
 * The `tenant/<tid>` prefix + `private: true` together close the
 * cross-tenant SSE leak (AUD-001): the hub only delivers a private
 * update to subscribers whose JWT `mercure.subscribe` claim authorises
 * the exact topic, and the mint endpoint scopes that claim to the
 * caller's tenant alone.
 *
 * Payload shape: `{type, occurredOn, data}` — `type` is the per-event
 * dot-cased name (kind-aware for create), `data` is a small dict the
 * subscriber can act on without re-fetching the row.
 */
final class MercurePublisherTest extends TestCase
{
    private const string TENANT_ID = '019ddb19-1111-7000-8000-aaaaaaaaaaaa';

    /**
     * @var list<Update>
     */
    private array $captured = [];

    #[Test]
    public function submittedForReviewCarriesTheCommentPayload(): void
    {
        $publisher = $this->makePublisher();
        $event = new ObjectSubmittedForReview(
            objectId: Uuid::fromString('019ddb19-a0f7-750a-a9cb-a6a6447ccb26'),
            tenantId: Uuid::fromString(self::TENANT_ID),
            comment: 'Gotowe do przeglądu',
        );

        $publisher->onObjectSubmittedForReview($event);

        self::assertCount(1, $this->captured);
        $payload = json_decode($this->captured[0]->getData(), true);
        self::assertIsArray($payload);
        self::assertSame('object.submitted_for_review', $payload['type']);
        self::assertIsArray($payload['data']);
        self::assertSame('Gotowe do przeglądu', $payload['data']['comment']);
    }

    #[Test]
    public function objectCreatedPushesPerRowAndBroadcastTopics(): void
    {
        $publisher = $this->makePublisher();
        $event = new ObjectCreated(
            objectId: Uuid::fromString('019ddb19-a0f7-750a-a9cb-a6a6447ccb26'),
            kind: ObjectKind::Product,
            code: 'SKU-1',
            tenantId: Uuid::fromString(self::TENANT_ID),
        );

        $publisher->onObjectCreated($event);

        self::assertCount(1, $this->captured);
        $update = $this->captured[0];
        self::assertSame([
            'https://pim.localhost/tenant/'.self::TENANT_ID.'/objects/019ddb19-a0f7-750a-a9cb-a6a6447ccb26',
            'https://pim.localhost/tenant/'.self::TENANT_ID.'/objects',
        ], $update->getTopics());
        self::assertTrue($update->isPrivate(), 'Catalog updates must be private so the hub enforces the subscribe claim.');

        $payload = json_decode($update->getData(), true);
        \assert(\is_array($payload));
        self::assertSame('object.created.product', $payload['type'] ?? null);
        $data = $payload['data'] ?? null;
        \assert(\is_array($data));
        self::assertSame('019ddb19-a0f7-750a-a9cb-a6a6447ccb26', $data['objectId'] ?? null);
        self::assertSame('product', $data['kind'] ?? null);
        self::assertSame('SKU-1', $data['code'] ?? null);
    }

    #[Test]
    public function skipsPublishUnderBulkContext(): void
    {
        // #import-oom — one Mercure HTTP push per object during a bulk import of
        // thousands of rows piled up Symfony HttpClient responses and OOM'd the
        // worker. Under BulkContext the per-row push is suppressed.
        $bulk = new BulkContext();
        $bulk->setBulk(true);
        $hub = new MockHub(
            'https://example.test/.well-known/mercure',
            new StaticTokenProvider('jwt'),
            function (Update $update): string {
                $this->captured[] = $update;

                return 'urn:uuid:'.bin2hex(random_bytes(8));
            },
        );
        $publisher = new MercurePublisher($hub, $bulk);

        $publisher->onObjectCreated(new ObjectCreated(
            objectId: Uuid::fromString('019ddb19-a0f7-750a-a9cb-a6a6447ccb26'),
            kind: ObjectKind::Product,
            code: 'SKU-1',
            tenantId: Uuid::fromString(self::TENANT_ID),
        ));

        self::assertSame([], $this->captured, 'no Mercure push may fire while a bulk run owns the process');
    }

    #[Test]
    public function objectAttributesChangedCarriesChangedAttributeCodes(): void
    {
        $publisher = $this->makePublisher();
        $event = new ObjectAttributesChanged(
            objectId: Uuid::fromString('019ddb19-a0f7-750a-a9cb-a6a6447ccb27'),
            tenantId: Uuid::v7(),
            changedAttributeCodes: ['color', 'weight'],
        );

        $publisher->onObjectAttributesChanged($event);

        self::assertCount(1, $this->captured);
        self::assertTrue($this->captured[0]->isPrivate());
        $payload = json_decode($this->captured[0]->getData(), true);
        \assert(\is_array($payload));
        self::assertSame('object.attributes_changed', $payload['type'] ?? null);
        $data = $payload['data'] ?? null;
        \assert(\is_array($data));
        self::assertSame(['color', 'weight'], $data['changedAttributeCodes'] ?? null);
    }

    #[Test]
    public function objectEnabledChangedCarriesEnabledFlag(): void
    {
        $publisher = $this->makePublisher();
        $event = new ObjectEnabledChanged(
            objectId: Uuid::fromString('019ddb19-a0f7-750a-a9cb-a6a6447ccb28'),
            tenantId: Uuid::v7(),
            enabled: false,
        );

        $publisher->onObjectEnabledChanged($event);

        self::assertCount(1, $this->captured);
        $payload = json_decode($this->captured[0]->getData(), true);
        \assert(\is_array($payload));
        self::assertSame('object.enabled_changed', $payload['type'] ?? null);
        $data = $payload['data'] ?? null;
        \assert(\is_array($data));
        self::assertFalse($data['enabled'] ?? true);
    }

    #[Test]
    public function topicForKindHelperBuildsTenantScopedKindFilteredTopic(): void
    {
        $tenant = Uuid::fromString(self::TENANT_ID);
        self::assertSame(
            'https://pim.localhost/tenant/'.self::TENANT_ID.'/objects/kind/product',
            MercurePublisher::topicForKind(ObjectKind::Product, $tenant),
        );
        self::assertSame(
            'https://pim.example.com/tenant/'.self::TENANT_ID.'/objects/kind/category',
            MercurePublisher::topicForKind(ObjectKind::Category, $tenant, 'https://pim.example.com'),
        );
    }

    private function makePublisher(): MercurePublisher
    {
        $this->captured = [];

        $hub = new MockHub(
            url: 'http://localhost/.well-known/mercure',
            jwtProvider: new StaticTokenProvider('test-jwt'),
            publisher: function (Update $update): string {
                $this->captured[] = $update;

                return 'urn:uuid:'.bin2hex(random_bytes(8));
            },
        );

        return new MercurePublisher($hub, new BulkContext());
    }
}
