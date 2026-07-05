<?php

declare(strict_types=1);

namespace App\Tests\Api\Dashboard;

use App\ApiConfigurator\Domain\Entity\WebhookDelivery;
use App\Catalog\Application\Query\SqlChannelCompletenessReport;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Channel\Domain\Entity\Channel;
use App\Dashboard\Application\Query\AlertFeedAggregator;
use App\Export\Feed\Domain\Entity\FeedRun;
use App\Export\Feed\Domain\Enum\FeedRunStatus;
use App\Export\Feed\Domain\Enum\FeedRunTrigger;
use App\Identity\Contracts\Policy\PermissionCheckerInterface;
use App\Import\Domain\Entity\ImportSession;
use App\Integration\Generic\Domain\Entity\Connection;
use App\Integration\Generic\Domain\Entity\SyncBinding;
use App\Integration\Generic\Domain\Entity\SyncRun;
use App\Integration\Generic\Domain\Enum\SyncDirection;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

use const JSON_THROW_ON_ERROR;

/**
 * DASH-09 (#2265, ADR-0026) — the action-center feed aggregates the REAL
 * status tables against Postgres: all five alert types, severity sort,
 * per-day webhook grouping, snapshot-based completeness-drop detection,
 * ack persistence + idempotency, window narrowing, per-type RBAC
 * filtering, input validation and tenant isolation.
 */
final class DashboardAlertsApiTest extends CatalogApiTestCase
{
    #[Test]
    public function feedAggregatesAllFiveTypesCriticalFirst(): void
    {
        $tenant = $this->demoTenant();
        $this->seedSyncFailure($tenant, 'Mtodo Marketplace', 412);
        $this->seedImportPartial($tenant, 'pim-catalog-0630.xlsx', 132);
        $this->seedFeedError($tenant, 'brak pola <price>');
        $this->seedWebhookDeadLetters($tenant, 'price.changed', 3);
        $this->seedCompletenessDrop($tenant);

        $response = $this->authenticatedClient()->request(
            'GET',
            '/api/dashboard/alerts?window=7d&limit=10',
        );

        self::assertResponseIsSuccessful();
        $body = $response->toArray();
        self::assertSame(5, $body['total']);
        self::assertSame(2, $body['critical']);
        self::assertSame(3, $body['warnings']);
        self::assertSame(5, $body['allCount']);

        $items = $body['items'];
        self::assertIsArray($items);
        self::assertCount(5, $items);
        $types = array_column($items, 'type');
        self::assertSame(
            ['critical', 'critical', 'warning', 'warning', 'warning'],
            array_column($items, 'severity'),
            'critical first',
        );
        self::assertContains('sync_run', $types);
        self::assertContains('import_session', $types);
        self::assertContains('feed_run', $types);
        self::assertContains('webhook', $types);
        self::assertContains('completeness_drop', $types);

        $sync = $this->paramsOf($items, 'sync_run');
        self::assertSame('Mtodo Marketplace', $sync['sourceName']);
        self::assertSame(412, $sync['failedCount']);
        $import = $this->paramsOf($items, 'import_session');
        self::assertSame('pim-catalog-0630.xlsx', $import['sourceName']);
        self::assertSame(132, $import['errorCount']);
        self::assertSame('brak pola <price>', $this->paramsOf($items, 'feed_run')['reason']);
        $webhook = $this->paramsOf($items, 'webhook');
        self::assertSame(3, $webhook['deliveries']);
        self::assertSame('price.changed', $webhook['eventType']);
        $drop = $this->paramsOf($items, 'completeness_drop');
        self::assertSame('Allegro', $drop['sourceName']);
        self::assertSame(40, $drop['avgPct']);
        self::assertSame(90, $drop['previousPct']);
    }

    /**
     * @param array<int|string, mixed> $items
     *
     * @return array<string, mixed>
     */
    private function paramsOf(array $items, string $type): array
    {
        foreach ($items as $item) {
            if (!\is_array($item) || ($item['type'] ?? null) !== $type) {
                continue;
            }
            $params = $item['params'] ?? null;
            if (\is_array($params)) {
                /* @var array<string, mixed> $params */
                return $params;
            }
        }
        self::fail(\sprintf('No alert of type "%s" in the feed.', $type));
    }

    #[Test]
    public function acknowledgedFingerprintDisappearsAndAckStaysIdempotent(): void
    {
        $tenant = $this->demoTenant();
        $this->seedFeedError($tenant, 'x');

        $client = $this->authenticatedClient();
        $feed = $client->request('GET', '/api/dashboard/alerts?window=7d&limit=10')->toArray();
        self::assertSame(1, $feed['total']);
        $items = $feed['items'];
        self::assertIsArray($items);
        $first = $items[0] ?? null;
        self::assertIsArray($first);
        $fingerprint = $first['fingerprint'] ?? '';
        self::assertIsString($fingerprint);
        self::assertStringStartsWith('feed_run:', $fingerprint);

        $client->request('POST', \sprintf('/api/dashboard/alerts/%s/ack', $fingerprint));
        self::assertResponseStatusCodeSame(204);
        // Idempotent: the second ack is a no-op, not a 500.
        $client->request('POST', \sprintf('/api/dashboard/alerts/%s/ack', $fingerprint));
        self::assertResponseStatusCodeSame(204);

        $after = $client->request('GET', '/api/dashboard/alerts?window=7d&limit=10')->toArray();
        self::assertSame(0, $after['total']);
        self::assertSame([], $after['items']);
    }

    #[Test]
    public function windowNarrowsTheFeed(): void
    {
        $tenant = $this->demoTenant();
        $run = $this->seedFeedError($tenant, 'stale');
        // Push the failure outside the 7d window but inside 30d.
        $this->em()->getConnection()->executeStatement(
            "UPDATE feed_runs SET started_at = NOW() - INTERVAL '10 days', completed_at = NOW() - INTERVAL '10 days' WHERE id = :id",
            ['id' => $run],
        );

        $client = $this->authenticatedClient();
        self::assertSame(
            0,
            $client->request('GET', '/api/dashboard/alerts?window=7d&limit=10')->toArray()['total'],
        );
        self::assertSame(
            1,
            $client->request('GET', '/api/dashboard/alerts?window=30d&limit=10')->toArray()['total'],
        );
    }

    #[Test]
    public function summaryCarriesTheOpenAlertCount(): void
    {
        $tenant = $this->demoTenant();
        $this->seedFeedError($tenant, 'y');
        $this->seedImportPartial($tenant, 'z.xlsx', 5);

        $body = $this->authenticatedClient()->request('GET', '/api/dashboard/summary')->toArray();
        self::assertSame(['count' => 2], $body['openAlerts']);
    }

    #[Test]
    public function perTypeRbacHidesSourcesTheUserCannotRead(): void
    {
        $tenant = $this->demoTenant();
        $this->seedFeedError($tenant, 'hidden for import-only users');
        $this->seedImportPartial($tenant, 'visible.xlsx', 1);

        self::getContainer()->get(TenantContext::class)->set($tenant);
        $importOnlyChecker = new class implements PermissionCheckerInterface {
            public function userHasPermission(Uuid $userId, string $permissionCode): bool
            {
                return 'import_session.read' === $permissionCode;
            }
        };
        $aggregator = new AlertFeedAggregator(
            $this->em(),
            self::getContainer()->get(TenantContext::class),
            new SqlChannelCompletenessReport($this->em(), self::getContainer()->get(TenantContext::class)),
            $importOnlyChecker,
        );

        $feed = $aggregator->alerts(Uuid::v7(), '7d', 10);
        self::assertSame(1, $feed['total'], 'feed_run alerts are invisible without exports.view_all');
        $items = $feed['items'];
        self::assertIsArray($items);
        $first = $items[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('import_session', $first['type'] ?? null);
    }

    #[Test]
    public function inputValidationAndAuthGuards(): void
    {
        $client = $this->authenticatedClient();

        $client->request('GET', '/api/dashboard/alerts?window=90d');
        self::assertResponseStatusCodeSame(400);
        $client->request('GET', '/api/dashboard/alerts?limit=0');
        self::assertResponseStatusCodeSame(400);
        // Passes the route requirement but not the fingerprint pattern
        // (the type prefix must be lowercase snake_case).
        $client->request('POST', '/api/dashboard/alerts/BadType:abc/ack');
        self::assertResponseStatusCodeSame(400);

        static::createClient()->request('GET', '/api/dashboard/alerts');
        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function foreignTenantAlertsNeverLeak(): void
    {
        $tenant = $this->demoTenant();
        $this->seedFeedError($tenant, 'mine');

        $beta = new Tenant('beta', 'Beta Tenant');
        $this->em()->persist($beta);
        $this->em()->flush();
        $this->seedFeedError($beta, 'theirs');
        $this->em()->clear();

        $body = $this->authenticatedClient()->request('GET', '/api/dashboard/alerts?window=7d&limit=10')->toArray();
        self::assertSame(1, $body['total']);
    }

    private function demoTenant(): Tenant
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        return $tenant;
    }

    private function seedSyncFailure(Tenant $tenant, string $connectionName, int $failedCount): void
    {
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $connection = new Connection('mtodo', $connectionName, 'https://api.example.test');
        $this->em()->persist($connection);
        $binding = new SyncBinding($connection, Uuid::v7(), SyncDirection::Inbound);
        $this->em()->persist($binding);
        $run = new SyncRun($binding, SyncDirection::Inbound);
        $this->em()->persist($run);
        $this->em()->flush();

        $this->em()->getConnection()->executeStatement(
            "UPDATE integration_sync_runs SET status = 'failed', failed_count = :failed, finished_at = NOW() WHERE id = :id",
            ['failed' => $failedCount, 'id' => $run->getId()->toRfc4122()],
        );
    }

    private function seedImportPartial(Tenant $tenant, string $fileName, int $errorCount): void
    {
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        $session = new ImportSession(Uuid::v7(), $type, $fileName, 1024);
        $this->em()->persist($session);
        $this->em()->flush();

        $this->em()->getConnection()->executeStatement(
            "UPDATE import_sessions SET status = 'partial', error_count = :errors, completed_at = NOW() WHERE id = :id",
            ['errors' => $errorCount, 'id' => $session->getId()->toRfc4122()],
        );
    }

    private function seedFeedError(Tenant $tenant, string $reason): string
    {
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $run = new FeedRun(Uuid::v7(), FeedRunTrigger::Manual, FeedRunStatus::Pending);
        $this->em()->persist($run);
        $this->em()->flush();

        $id = $run->getId()->toRfc4122();
        $this->em()->getConnection()->executeStatement(
            "UPDATE feed_runs SET status = 'error', error_message = :reason, completed_at = NOW() WHERE id = :id",
            ['reason' => $reason, 'id' => $id],
        );

        return $id;
    }

    private function seedWebhookDeadLetters(Tenant $tenant, string $eventType, int $count): void
    {
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $profileId = Uuid::v7();
        $ids = [];
        for ($i = 0; $i < $count; ++$i) {
            $delivery = new WebhookDelivery($profileId, $eventType, 'https://hook.example.test', ['i' => $i]);
            $this->em()->persist($delivery);
            $ids[] = $delivery->getId()->toRfc4122();
        }
        $this->em()->flush();

        $this->em()->getConnection()->executeStatement(
            "UPDATE api_webhook_deliveries SET status = 'failed', attempts = 5, http_status = 503, updated_at = NOW() WHERE id IN (:ids)",
            ['ids' => $ids],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
    }

    private function seedCompletenessDrop(Tenant $tenant): void
    {
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $this->em()->persist(new Channel('allegro', 'Allegro'));
        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $type);
        $object = new CatalogObject($type, 'DROP-1');
        $object->recordCompleteness(['global' => 40, 'per_channel' => ['allegro' => 40]]);
        $this->em()->persist($object);
        $this->em()->flush();

        // Yesterday's snapshot says allegro was healthy (90%).
        $this->em()->getConnection()->executeStatement(
            'INSERT INTO dashboard_snapshots '
            .'(id, tenant_id, snapshot_date, products_total, publish_ready_count, avg_completeness_pct, per_channel, created_at) '
            .'VALUES (:id, :tenant, CURRENT_DATE - 1, 1, 1, 90, :per_channel, NOW())',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'tenant' => $tenant->getId()->toRfc4122(),
                'per_channel' => json_encode(['allegro' => ['avgPct' => 90, 'readyCount' => 1]], JSON_THROW_ON_ERROR),
            ],
        );
    }
}
