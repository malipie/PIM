<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search\Application;

use App\Catalog\Domain\ObjectKind;
use App\Identity\Application\CurrentTenantProvider;
use App\Identity\Domain\Entity\User;
use App\Search\Application\CatalogSearchService;
use App\Search\Infrastructure\MeilisearchClientFactory;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Repository\DoctrineTenantRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * AUD-004 (#1574) — unit-level guard for Meilisearch filter-key injection.
 *
 * Deterministic counterpart to the live-Meili ApiTest: a filter key that is
 * not a known filterable attribute (e.g. `parentId IS NULL OR tenantId`,
 * which smuggles a low-precedence `OR` that closes the tenant AND-scope)
 * must be rejected with a 400 *before* the service touches Meilisearch.
 *
 * We wire the service with a {@see MeilisearchClientFactory} that has no URL
 * configured, so `create()` throws {@see LogicException}. If the key
 * whitelist is missing (pre-fix), the service builds the filter expression
 * and reaches `create()` → `LogicException` surfaces. With the fix, the key
 * is rejected up front → `BadRequestHttpException` and `create()` is never
 * called. The exception *type* is therefore the assertion.
 */
final class CatalogSearchServiceFilterKeyTest extends TestCase
{
    #[Test]
    public function rejectsFilterKeyCarryingMeiliOperator(): void
    {
        $service = $this->serviceWithTenant();

        $this->expectException(BadRequestHttpException::class);

        $service->search(
            kind: ObjectKind::Product,
            query: '',
            filters: ['parentId IS NULL OR tenantId' => '00000000-0000-7000-8000-000000000000'],
        );
    }

    #[Test]
    public function rejectsUnknownPlainFilterKey(): void
    {
        $service = $this->serviceWithTenant();

        $this->expectException(BadRequestHttpException::class);

        $service->search(
            kind: ObjectKind::Product,
            query: '',
            filters: ['totally_unknown_field' => 'x'],
        );
    }

    #[Test]
    public function rejectsUnknownKeyInArrayValuedFilter(): void
    {
        $service = $this->serviceWithTenant();

        $this->expectException(BadRequestHttpException::class);

        $service->search(
            kind: ObjectKind::Product,
            query: '',
            filters: ['brand" OR tenantId' => ['a', 'b']],
        );
    }

    #[Test]
    public function rejectsUnknownRangeFilterKey(): void
    {
        $service = $this->serviceWithTenant();

        $this->expectException(BadRequestHttpException::class);

        $service->search(
            kind: ObjectKind::Product,
            query: '',
            rangeFilters: ['price >= 0 OR tenantId' => ['gte' => 1.0]],
        );
    }

    /**
     * Whitelisted attribute keys must NOT be rejected by the validator. The
     * service swallows the downstream Meili failure (unconfigured factory)
     * inside its try/catch and degrades to an empty result — the point here
     * is that NO {@see BadRequestHttpException} escapes for a legitimate key,
     * proving the whitelist accepts known fields instead of blanket-rejecting.
     */
    #[Test]
    public function acceptsWhitelistedFilterKey(): void
    {
        $service = $this->serviceWithTenant();

        // `enabled` is a RESERVED_FILTERABLE — must pass the key whitelist.
        // Meili is unreachable (url=null) so the service returns a degraded
        // result rather than 400; the absence of a thrown 400 is the assertion.
        $result = $service->search(
            kind: ObjectKind::Product,
            query: '',
            filters: ['enabled' => 'true'],
        );

        self::assertArrayHasKey('hits', $result);
        self::assertArrayHasKey('totalHits', $result);
    }

    /**
     * AUD-070 (#1614) — when the Meilisearch backend is unreachable the
     * service must NOT collapse to a silent empty result (indistinguishable
     * from "no matches"); it flags the result `degraded:true` so the
     * controller can answer 503 instead of a misleading empty `200`.
     *
     * The factory is wired with `url=null`, so `create()` throws inside the
     * service's try/catch — the same path a real connection/timeout failure
     * takes. RED before the fix (returned `emptyResult()` → no `degraded`
     * flag / `false`), GREEN after.
     */
    #[Test]
    public function flagsResultDegradedWhenBackendUnavailable(): void
    {
        $service = $this->serviceWithTenant();

        $result = $service->search(kind: ObjectKind::Product, query: 'anything');

        self::assertTrue($result['degraded'], 'backend outage must be flagged degraded, not a silent empty');
        self::assertSame([], $result['hits']);
        self::assertSame(0, $result['totalHits']);
    }

    /**
     * AUD-070 (#1614) — the degraded flag is reserved for backend outages.
     * A request with no active tenant context is a legitimate empty result
     * (defence-in-depth refusal to hit the hub), so it must report
     * `degraded:false` — otherwise every unauthenticated edge would falsely
     * read as "search down".
     */
    #[Test]
    public function emptyResultWithoutTenantIsNotDegraded(): void
    {
        // No token set → CurrentTenantProvider yields null → emptyResult().
        $factory = new MeilisearchClientFactory(null, null);
        $tenantProvider = new CurrentTenantProvider(
            new TokenStorage(),
            $this->createStub(DoctrineTenantRepository::class),
        );
        $service = new CatalogSearchService($factory, $tenantProvider, new TenantContext());

        $result = $service->search(kind: ObjectKind::Product, query: 'anything');

        self::assertFalse($result['degraded'], 'a no-tenant empty result is not a backend outage');
        self::assertSame([], $result['hits']);
    }

    private function serviceWithTenant(): CatalogSearchService
    {
        $tenant = new Tenant('demo', 'Demo Tenant');
        $user = new User($tenant, 'admin@demo.localhost', '', ['ROLE_USER']);

        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));

        // No DoctrineTenantRepository needed: the User implements TenantAware,
        // so CurrentTenantProvider short-circuits to $user->getTenant().
        $tenantProvider = new CurrentTenantProvider(
            $tokenStorage,
            $this->createStub(DoctrineTenantRepository::class),
        );

        // url=null → create() throws LogicException, marking "reached Meili".
        $factory = new MeilisearchClientFactory(null, null);

        return new CatalogSearchService($factory, $tenantProvider, new TenantContext());
    }

    /**
     * #2977 — a worker has no security token, so the tenant reaches the
     * service only through {@see TenantContext}. Before the fix the
     * service read the provider alone and answered a silent empty result
     * for every worker-side search — the agent's "the catalog is empty"
     * on a tenant with 7 products.
     */
    #[Test]
    public function tenantBoundWithoutSecurityTokenScopesTheSearch(): void
    {
        $context = new TenantContext();
        $context->set(new Tenant('demo', 'Demo Tenant'));

        // No token → the provider yields null; only the bound context can
        // scope this search.
        $tenantProvider = new CurrentTenantProvider(
            new TokenStorage(),
            $this->createStub(DoctrineTenantRepository::class),
        );
        // url=null → create() throws, which the service reports as
        // degraded. Reaching that branch at all proves the tenant gate was
        // passed; an unscoped call returns the non-degraded empty result.
        $service = new CatalogSearchService(new MeilisearchClientFactory(null, null), $tenantProvider, $context);

        $result = $service->search(kind: ObjectKind::Product, query: 'anything');

        self::assertTrue(
            $result['degraded'],
            'the search must reach the engine, not short-circuit on a missing tenant',
        );
    }
}
