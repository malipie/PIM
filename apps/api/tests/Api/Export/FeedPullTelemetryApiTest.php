<?php

declare(strict_types=1);

namespace App\Tests\Api\Export;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Catalog\Domain\ObjectKind;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * XMLF-P3-06 [SEC] — public pull URL telemetry + per-token rate limit.
 *
 * Pins the contract of the counters: only real serves count (200 and 304 —
 * a conditional revalidation still proves the crawler polls), a never-cached
 * feed counts zero even when hammered, and the per-feed budget (5/h in the
 * test env, 120/h in prod) answers 429 + Retry-After once exhausted.
 */
final class FeedPullTelemetryApiTest extends CatalogApiTestCase
{
    #[Test]
    public function servedPullsAreCountedAggregatedAndStamped(): void
    {
        $admin = $this->authenticatedClient();
        $feedId = $this->createFeed($admin, 'telemetry_feed');
        $url = $this->mintPullUrl($admin, $feedId);

        self::assertSame(202, $admin->request('POST', '/api/feeds/'.$feedId.'/regenerate')->getStatusCode());

        $anon = static::createClient();
        $first = $anon->request('GET', $url);
        self::assertSame(200, $first->getStatusCode());
        $etag = $first->getHeaders(throw: false)['etag'][0] ?? null;
        self::assertNotNull($etag);

        self::assertSame(200, $anon->request('GET', $url)->getStatusCode());
        // Conditional revalidation — 304 without a body still counts as a pull.
        $revalidated = $anon->request('GET', $url, ['headers' => ['If-None-Match' => $etag]]);
        self::assertSame(304, $revalidated->getStatusCode());

        $stats = $this->authenticatedClient()->request('GET', '/api/feeds/'.$feedId.'/pull-stats');
        self::assertSame(200, $stats->getStatusCode());
        $body = $stats->toArray(false);
        self::assertSame(3, $body['pulls_24h']);
        self::assertNotNull($body['last_pulled_at']);
        $spark = $body['spark'];
        self::assertIsArray($spark);
        self::assertCount(24, $spark);
        self::assertSame(3, array_sum(array_column($spark, 'count')));

        // The tenant-wide aggregate (hub KPI strip) sees the same three hits.
        $global = $this->authenticatedClient()->request('GET', '/api/feeds/pull-stats');
        self::assertSame(200, $global->getStatusCode());
        self::assertSame(3, $global->toArray(false)['pulls_24h']);

        // The feed detail exposes the freshness stamp for the monitor.
        $detail = $this->authenticatedClient()->request('GET', '/api/feeds/'.$feedId)->toArray(false);
        self::assertNotNull($detail['last_pulled_at']);
    }

    #[Test]
    public function pullBudgetExhaustionReturns429AndUncachedHitsCountZero(): void
    {
        $admin = $this->authenticatedClient();
        $feedId = $this->createFeed($admin, 'limited_feed');
        // Deliberately NOT regenerated: every pull 404s after the limiter
        // ticks (no cached artifact), which pins two contracts at once —
        // the budget burns before any storage read, and un-served hits
        // never inflate the telemetry.
        $url = $this->mintPullUrl($admin, $feedId);

        $anon = static::createClient();
        for ($i = 1; $i <= 5; ++$i) {
            $status = $anon->request('GET', $url)->getStatusCode();
            self::assertSame(404, $status, 'Attempt #'.$i.' must burn budget, not be limited yet.');
        }

        $throttled = $anon->request('GET', $url);
        self::assertSame(429, $throttled->getStatusCode());
        self::assertNotNull($throttled->getHeaders(throw: false)['retry-after'][0] ?? null);

        $stats = $this->authenticatedClient()->request('GET', '/api/feeds/'.$feedId.'/pull-stats')->toArray(false);
        self::assertSame(0, $stats['pulls_24h']);
        self::assertNull($stats['last_pulled_at']);
    }

    #[Test]
    public function pullStatsAreNotPublic(): void
    {
        $anon = static::createClient();

        self::assertSame(401, $anon->request('GET', '/api/feeds/pull-stats')->getStatusCode());
    }

    private function createFeed(Client $client, string $code): string
    {
        $created = $client->request('POST', '/api/feeds', ['json' => [
            'template_kind' => 'custom',
            'code' => $code,
            'name' => 'Telemetry feed',
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

    private function mintPullUrl(Client $client, string $feedId): string
    {
        $minted = $client->request('POST', '/api/feeds/'.$feedId.'/token');
        self::assertSame(201, $minted->getStatusCode());

        $url = $minted->toArray(false)['url'];
        self::assertIsString($url);

        return $url;
    }
}
