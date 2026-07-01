<?php

declare(strict_types=1);

namespace App\Tests\Api\Export;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Catalog\Domain\ObjectKind;
use App\Export\Feed\Domain\Entity\FeedRun;
use App\Export\Feed\Domain\Entity\FeedRunLog;
use App\Export\Feed\Domain\Enum\FeedRunLogLevel;
use App\Export\Feed\Domain\Enum\FeedRunTrigger;
use App\Export\Feed\Domain\Repository\FeedRunLogRepositoryInterface;
use App\Export\Feed\Domain\Repository\FeedRunRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * XMLF-P4-03 — monitor read side: global run history (sort/filter/cursor),
 * per-feed scoping, log drill-down, 24h KPI window and the health card.
 */
final class FeedRunMonitorApiTest extends CatalogApiTestCase
{
    #[Test]
    public function globalRunListSortsFiltersAndPaginates(): void
    {
        $admin = $this->authenticatedClient();
        $feedA = $this->createFeed($admin, 'feed_a');
        $feedB = $this->createFeed($admin, 'feed_b');

        $success = $this->seedRun($feedA, 'done', warnings: 0);
        $warning = $this->seedRun($feedA, 'done', warnings: 3);
        $error = $this->seedRun($feedA, 'error', errorMessage: 'missing required <price> slot');
        $other = $this->seedRun($feedB, 'done', warnings: 0);

        $list = $admin->request('GET', '/api/feed-runs')->toArray(false);
        $ids = self::idsOf($list['items']);
        self::assertCount(4, $ids);
        // Newest first (UUIDv7 keyset order == started_at order).
        self::assertSame($other->getId()->toRfc4122(), $ids[0]);
        self::assertSame('feed_a', self::row($list['items'], 3)['feed_code'], 'global list carries the feed identity');

        $warnings = $admin->request('GET', '/api/feed-runs?status=warning')->toArray(false)['items'];
        self::assertSame([$warning->getId()->toRfc4122()], self::idsOf($warnings));
        self::assertSame('warning', self::row($warnings, 0)['health']);

        $errors = $admin->request('GET', '/api/feed-runs?status=error')->toArray(false)['items'];
        self::assertSame([$error->getId()->toRfc4122()], self::idsOf($errors));

        // Cursor walk: 2 + 2, no overlap, terminates.
        $first = $admin->request('GET', '/api/feed-runs?limit=2')->toArray(false);
        $firstIds = self::idsOf($first['items']);
        self::assertCount(2, $firstIds);
        $cursor = $first['next_cursor'];
        self::assertIsString($cursor);
        $second = $admin->request('GET', '/api/feed-runs?limit=2&cursor='.$cursor)->toArray(false);
        $secondIds = self::idsOf($second['items']);
        self::assertCount(2, $secondIds);
        $walked = array_merge($firstIds, $secondIds);
        self::assertCount(4, array_unique($walked));
        self::assertContains($success->getId()->toRfc4122(), $walked);
    }

    #[Test]
    public function perFeedHistoryIsScopedAndCrossFeedRunIs404(): void
    {
        $admin = $this->authenticatedClient();
        $feedA = $this->createFeed($admin, 'scoped_a');
        $feedB = $this->createFeed($admin, 'scoped_b');

        $runA = $this->seedRun($feedA, 'done');
        $runB = $this->seedRun($feedB, 'done');

        $items = $admin->request('GET', '/api/feeds/'.$feedA.'/runs')->toArray(false)['items'];
        self::assertSame([$runA->getId()->toRfc4122()], self::idsOf($items));

        self::assertSame(200, $admin->request('GET', '/api/feeds/'.$feedA.'/runs/'.$runA->getId()->toRfc4122())->getStatusCode());
        // The other feed's run must not resolve through this feed's path.
        self::assertSame(404, $admin->request('GET', '/api/feeds/'.$feedA.'/runs/'.$runB->getId()->toRfc4122())->getStatusCode());
        self::assertSame(404, $admin->request('GET', '/api/feeds/'.Uuid::v7()->toRfc4122().'/runs')->getStatusCode());
    }

    #[Test]
    public function logDrilldownFiltersByLevelAndPaginates(): void
    {
        $admin = $this->authenticatedClient();
        $feedId = $this->createFeed($admin, 'logs_feed');
        $run = $this->seedRun($feedId, 'done', warnings: 2);
        $this->seedLogs($run, [
            [FeedRunLogLevel::Warning, 'KL-1', 'g:gtin', 'missing required g:gtin — skipped'],
            [FeedRunLogLevel::Warning, 'KL-2', 'g:image_link', 'empty g:image_link — skipped'],
            [FeedRunLogLevel::Info, 'KL-3', 'g:title', 'g:title truncated to 150 chars'],
        ]);

        $url = '/api/feeds/'.$feedId.'/runs/'.$run->getId()->toRfc4122().'/logs';

        $all = $admin->request('GET', $url)->toArray(false)['items'];
        self::assertIsArray($all);
        self::assertCount(3, $all);
        self::assertSame('KL-1', self::row($all, 0)['object_sku'], 'oldest first — emission order');
        self::assertSame('g:gtin', self::row($all, 0)['slot']);

        $warningsOnly = $admin->request('GET', $url.'?level=warning')->toArray(false)['items'];
        self::assertIsArray($warningsOnly);
        self::assertCount(2, $warningsOnly);

        $first = $admin->request('GET', $url.'?limit=2')->toArray(false);
        $cursor = $first['next_cursor'];
        self::assertIsString($cursor);
        $rest = $admin->request('GET', $url.'?limit=2&cursor='.$cursor)->toArray(false);
        self::assertIsArray($rest['items']);
        self::assertCount(1, $rest['items']);
        self::assertSame('KL-3', self::row($rest['items'], 0)['object_sku']);
    }

    #[Test]
    public function kpiCountsOnlyTheLast24Hours(): void
    {
        $admin = $this->authenticatedClient();
        $feedId = $this->createFeed($admin, 'kpi_feed');

        $this->seedRun($feedId, 'done', skipped: 5);
        $inWindowError = $this->seedRun($feedId, 'error', errorMessage: 'feed B2B — missing <price>');
        $oldError = $this->seedRun($feedId, 'error', errorMessage: 'ancient failure');
        $this->backdate($oldError, hours: 25);

        $kpi = $admin->request('GET', '/api/feed-runs/kpi')->toArray(false);

        self::assertSame(2, $kpi['regenerations_24h'], 'the 25h-old run is out of the window');
        self::assertSame(5, $kpi['skipped_24h']);
        self::assertSame(1, $kpi['errors_24h']);
        $lastError = $kpi['last_error'];
        self::assertIsArray($lastError);
        self::assertSame($inWindowError->getId()->toRfc4122(), $lastError['run_id']);
        self::assertSame('feed B2B — missing <price>', $lastError['message']);
        self::assertSame(0, $kpi['items_syndicated'], 'no cached artifact yet');
        self::assertIsArray($kpi['feeds']);
        self::assertSame(1, $kpi['feeds']['active']);
    }

    #[Test]
    public function healthExposesCoverageAndTheLastRun(): void
    {
        $admin = $this->authenticatedClient();
        $feedId = $this->createFeed($admin, 'health_feed');
        $run = $this->seedRun($feedId, 'done', warnings: 1);

        $health = $admin->request('GET', '/api/feeds/'.$feedId.'/health')->toArray(false);

        self::assertSame($feedId, $health['feed_id']);
        self::assertSame(['mapped' => 1, 'total' => 1], $health['coverage'], 'the sku slot is mapped');
        self::assertNull($health['items_syndicated'], 'never regenerated — nothing syndicated');
        self::assertNull($health['next_run'], 'scheduler engine is a documented follow-up');
        $lastRun = $health['last_run'];
        self::assertIsArray($lastRun);
        self::assertSame($run->getId()->toRfc4122(), $lastRun['id']);
        self::assertSame('warning', $lastRun['health']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(mixed $items, int $index): array
    {
        self::assertIsArray($items);
        $row = $items[$index] ?? null;
        self::assertIsArray($row);
        /** @var array<string, mixed> $typed */
        $typed = $row;

        return $typed;
    }

    /**
     * @return list<string>
     */
    private static function idsOf(mixed $items): array
    {
        self::assertIsArray($items);
        $ids = [];
        foreach ($items as $row) {
            self::assertIsArray($row);
            $id = $row['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }

    private function seedRun(
        string $feedId,
        string $terminal,
        int $warnings = 0,
        int $skipped = 0,
        ?string $errorMessage = null,
    ): FeedRun {
        self::getContainer()->get(TenantContext::class)->set($this->tenant());
        $run = new FeedRun(Uuid::fromString($feedId), FeedRunTrigger::Manual);
        $run->markRunning();
        if ('error' === $terminal) {
            $run->markError($errorMessage ?? 'boom');
        } else {
            $run->markDone(itemCount: 10, skippedCount: $skipped, warningCount: $warnings, filePath: '/tmp/feed.xml', fileSizeBytes: 1024, durationMs: 42);
        }

        $runs = self::getContainer()->get(FeedRunRepositoryInterface::class);
        $runs->save($run);

        return $run;
    }

    /**
     * @param list<array{0: FeedRunLogLevel, 1: string, 2: string, 3: string}> $lines
     */
    private function seedLogs(FeedRun $run, array $lines): void
    {
        self::getContainer()->get(TenantContext::class)->set($this->tenant());
        $logs = [];
        foreach ($lines as [$level, $sku, $slot, $message]) {
            $logs[] = new FeedRunLog($run->getId(), $level, $message, $sku, $slot);
        }
        self::getContainer()->get(FeedRunLogRepositoryInterface::class)->saveMany($logs);
    }

    private function backdate(FeedRun $run, int $hours): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            'UPDATE feed_runs SET started_at = started_at - make_interval(hours => :h) WHERE id = :id',
            ['h' => $hours, 'id' => $run->getId()->toRfc4122()],
        );
    }

    private function tenant(): Tenant
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        return $tenant;
    }

    private function createFeed(Client $client, string $code): string
    {
        $created = $client->request('POST', '/api/feeds', ['json' => [
            'template_kind' => 'custom',
            'code' => $code,
            'name' => 'Monitor feed '.$code,
            'object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
            'descriptor' => [
                'root' => ['element' => 'products'],
                'item' => [
                    'element' => 'product',
                    'slots' => [['slot' => 'sku', 'node' => 'element', 'required' => true, 'fmt' => 'text']],
                ],
            ],
            'field_mappings' => [['slot' => 'sku', 'source' => ['kind' => 'attribute', 'ref' => 'sku']]],
            'locale' => 'pl',
        ]]);
        self::assertSame(201, $created->getStatusCode());

        $id = $created->toArray(false)['id'];
        self::assertIsString($id);

        return $id;
    }
}
