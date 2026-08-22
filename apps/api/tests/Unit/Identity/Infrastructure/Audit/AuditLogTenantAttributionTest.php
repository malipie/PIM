<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Audit;

use App\Identity\Application\Audit\AuditLogRequestMapper;
use App\Identity\Application\Audit\AuditTenantResolver;
use App\Identity\Application\CurrentTenantProvider;
use App\Identity\Domain\Entity\AuditLog;
use App\Identity\Domain\Repository\AuditLogRepositoryInterface;
use App\Identity\Infrastructure\Audit\AuditLogListener;
use App\Shared\Application\TenantAware;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Repository\DoctrineTenantRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * #2976 — the audit row must be attributed to the tenant the request
 * actually ran under, not only to the one derivable from a principal.
 *
 * Production symptom: every `GET /api/catalogs/pull/{tenantId}/{token}.pdf`
 * answered 500 with `new row violates row-level security policy for table
 * "audit_logs"`. That route is a signed, session-less link: it binds the
 * tenant itself (GUC + TenantContext) but has no principal, so the
 * listener wrote `tenant_id = NULL` while the GUC named a tenant — which
 * the policy's WITH CHECK clause rejects.
 *
 * The RLS half cannot be reproduced here (the test connection owns the
 * tables and bypasses RLS), so this pins the mechanism: the row takes its
 * tenant from the same place the GUC does.
 *
 * #2978 follow-up: the resolution now lives in {@see AuditTenantResolver},
 * shared by all three audit writers. The first fix only reached the HTTP
 * listener, so `pim:agent:start` hit the identical rejection on production
 * the moment console commands got a GUC at all.
 */
final class AuditLogTenantAttributionTest extends TestCase
{
    #[Test]
    public function tenantBoundWithoutPrincipalIsRecordedOnTheAuditRow(): void
    {
        $tenant = new Tenant('trzeci', 'Trzeci Tenant');
        $context = new TenantContext();
        $context->set($tenant);

        $saved = $this->dispatchResponse($context, providerTenant: null);

        self::assertNotNull($saved);
        self::assertSame(
            $tenant->getId()->toRfc4122(),
            $saved->getTenantId()?->toRfc4122(),
            'A session-less signed route binds the tenant itself; the audit row must carry it, otherwise RLS rejects the insert.',
        );
    }

    /**
     * Flows that authenticate WITHIN the request (login) reach
     * kernel.response with an empty context but a resolvable principal.
     * The provider fallback keeps their attribution intact.
     */
    #[Test]
    public function principalTenantIsUsedWhenNoTenantIsBound(): void
    {
        $tenant = new Tenant('demo', 'Demo Tenant');

        $saved = $this->dispatchResponse(new TenantContext(), providerTenant: $tenant);

        self::assertNotNull($saved);
        self::assertSame($tenant->getId()->toRfc4122(), $saved->getTenantId()?->toRfc4122());
    }

    #[Test]
    public function anonymousRequestWithNoTenantAtAllStaysUnattributed(): void
    {
        $saved = $this->dispatchResponse(new TenantContext(), providerTenant: null);

        self::assertNotNull($saved);
        self::assertNull($saved->getTenantId());
    }

    /**
     * Minimal principal carrying a tenant — the shape
     * CurrentTenantProvider resolves from.
     */
    private function tenantAwareUser(Tenant $tenant): UserInterface
    {
        return new class($tenant) implements TenantAware, UserInterface {
            public function __construct(private Tenant $tenant)
            {
            }

            public function getTenant(): Tenant
            {
                return $this->tenant;
            }

            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return 'audit-probe';
            }
        };
    }

    private function dispatchResponse(TenantContext $context, ?Tenant $providerTenant): ?AuditLog
    {
        $repository = new class implements AuditLogRepositoryInterface {
            public ?AuditLog $captured = null;

            public function save(AuditLog $entry): void
            {
                $this->captured = $entry;
            }
        };

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        // CurrentTenantProvider is final — drive the real one through its
        // only non-fallback input, the security token.
        $tokenStorage = new TokenStorage();
        if ($providerTenant instanceof Tenant) {
            $tokenStorage->setToken(new UsernamePasswordToken($this->tenantAwareUser($providerTenant), 'main'));
        }
        $provider = new CurrentTenantProvider(
            $tokenStorage,
            $this->createStub(DoctrineTenantRepository::class),
            null,
            'prod',
        );

        $listener = new AuditLogListener(
            $repository,
            $security,
            new AuditTenantResolver($context, $provider),
            new AuditLogRequestMapper(),
        );

        $request = Request::create('/api/catalogs/pull/019ffef5-f6e0-7816-ae16-8cb2d60ae3ad/tok.pdf');
        $request->attributes->set('_route', 'pim_catalogs_pull');

        $listener->onKernelResponse(new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new Response(),
        ));

        return $repository->captured;
    }
}
