<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Metrics\QueryDurationHistogram;
use PHPUnit\Framework\Attributes\Test;

use const JSON_THROW_ON_ERROR;

/**
 * GOLIVE #2795 — query-count budget for the hot catalog read paths.
 *
 * Why count queries instead of measuring time: latency depends on the
 * machine, so a time threshold either flakes on a busy runner or is set so
 * loose it catches nothing. #2234 proved the point — `GET /api/products`
 * shipped a **constant ~144 queries per request** (four SELECTs per
 * attribute from the permission resolver) and no gate noticed, because on a
 * small dev catalogue the endpoint still answered fast enough to look fine.
 * Query count is hardware-independent and fails loudly.
 *
 * The counter is the one production already uses: `QueryTimingMiddleware`
 * wraps every `query()` / `exec()` / prepared-statement `execute()` and
 * feeds {@see QueryDurationHistogram}, whose `count()` is a running total.
 * No test-only instrumentation, so this measures exactly what the runtime
 * does.
 *
 * The fixture deliberately seeds a WIDE schema (many attributes on few
 * products). A regression that resolves per attribute is invisible on the
 * three-attribute fixtures the rest of the suite uses — that is precisely
 * how #2234 survived review.
 */
final class QueryBudgetApiTest extends CatalogApiTestCase
{
    /**
     * Wide enough that a per-attribute regression cannot hide inside the
     * budget, small enough to keep the fixture cheap.
     */
    private const int ATTRIBUTE_COUNT = 12;

    /**
     * Ceiling for one `GET /api/products` page, frozen after #2794 brought
     * permission resolution down from 4×N to 4 queries.
     *
     * Raising this is allowed — but only together with a comment saying
     * which behaviour needed the extra round-trips, the same discipline the
     * max-lines guards use. Silently bumping the number defeats the gate.
     */
    private const int COLLECTION_BUDGET = 40;

    #[Test]
    public function productCollectionStaysWithinQueryBudget(): void
    {
        $client = $this->seedWideCatalogue();

        $queries = $this->countQueriesFor($client, '/api/products?itemsPerPage=30');

        self::assertLessThanOrEqual(
            self::COLLECTION_BUDGET,
            $queries,
            \sprintf(
                'GET /api/products issued %d queries against %d attributes, budget is %d. '
                .'A jump here usually means something resolves per attribute or per row again (#2234).',
                $queries,
                self::ATTRIBUTE_COUNT,
                self::COLLECTION_BUDGET,
            ),
        );
    }

    /**
     * The actual N+1 detector. A ceiling can be satisfied by an endpoint
     * that is merely small; invariance across page sizes is what separates
     * "resolved once per page" from "resolved per row".
     */
    #[Test]
    public function queryCountDoesNotScaleWithPageSize(): void
    {
        $client = $this->seedWideCatalogue();

        $one = $this->countQueriesFor($client, '/api/products?itemsPerPage=1');
        $ten = $this->countQueriesFor($client, '/api/products?itemsPerPage=10');

        self::assertLessThanOrEqual(
            $one + 2,
            $ten,
            \sprintf(
                'Query count scales with page size (%d queries for 1 item, %d for 10). '
                .'Something in the serialization path resolves per row instead of per page.',
                $one,
                $ten,
            ),
        );
    }

    /**
     * Counts the SQL statements one authenticated GET costs.
     *
     * `disableReboot()` keeps a single kernel — and therefore a single
     * histogram instance — alive across requests, so the before/after delta
     * is meaningful; without it the client rebuilds the container per
     * request and the counter resets.
     *
     * The first request is a warm-up: it pays one-off costs (ORM metadata
     * hydration, the schema-existence probes the permission policy memoises
     * for the worker's lifetime) that say nothing about the steady state
     * this budget describes.
     */
    private function countQueriesFor(Client $client, string $uri): int
    {
        /** @var QueryDurationHistogram $histogram */
        $histogram = self::getContainer()->get(QueryDurationHistogram::class);

        $client->request('GET', $uri);
        self::assertResponseIsSuccessful();

        $before = $histogram->count();
        $client->request('GET', $uri);
        self::assertResponseIsSuccessful();

        return $histogram->count() - $before;
    }

    /**
     * Seeds {@see ATTRIBUTE_COUNT} attributes and a handful of products
     * carrying all of them, then returns a client pinned to one kernel.
     */
    private function seedWideCatalogue(): Client
    {
        $attributes = [];
        for ($i = 1; $i <= self::ATTRIBUTE_COUNT; ++$i) {
            $code = \sprintf('budget_attr_%02d', $i);
            $this->seedAttribute($code);
            $attributes[$code] = 'value-'.$i;
        }

        $client = $this->authenticatedClient();
        $client->disableReboot();

        $objectTypeId = $this->objectTypeIdFor(ObjectKind::Product);
        for ($i = 1; $i <= 5; ++$i) {
            $client->request('POST', '/api/products', [
                'headers' => ['content-type' => 'application/ld+json'],
                'body' => json_encode([
                    'code' => \sprintf('budget-sku-%02d', $i),
                    'objectTypeId' => $objectTypeId,
                    'attributes' => $attributes,
                ], JSON_THROW_ON_ERROR),
            ]);
            self::assertResponseIsSuccessful();
        }

        return $client;
    }

    private function seedAttribute(string $code): void
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        self::getContainer()->get(TenantContext::class)->set($tenant);

        $attribute = new Attribute($code, ['en' => ucfirst($code)], AttributeType::Text);
        self::getContainer()->get(AttributeRepositoryInterface::class)->save($attribute);
    }
}
