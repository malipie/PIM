<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Controller;

use App\Catalog\Contracts\Service\TenantCatalogBootstrap;
use App\Identity\Application\InvitationService;
use App\Identity\Application\SeedTenantPrdRolesService;
use App\Identity\Application\SuperAdmin\PlatformOperatorGuard;
use App\Identity\Application\SuperAdmin\SuperAdminContext;
use App\Identity\Application\SuperAdmin\SuperAdminTenantResponseBuilder;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Rbac\RbacMatrix;
use App\Identity\Infrastructure\Provisioning\ProvisioningQueue;
use App\Shared\Domain\Repository\TenantRepositoryInterface;
use App\Shared\Domain\Tenant;
use App\Shared\Domain\TenantSubdomain;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * RBAC-P5-021 (#711) — write endpoints for the Super Admin operator
 * panel.
 *
 *   - POST   /api/admin/tenants                    — create new tenant
 *                                                   + auto-seed PRD
 *                                                   roles + invite the
 *                                                   default Owner
 *   - PATCH  /api/admin/tenants/{id}               — update name / plan
 *                                                   / domain (status
 *                                                   changes have own
 *                                                   endpoints)
 *   - POST   /api/admin/tenants/{id}/suspend       — flip status to
 *                                                   `suspended`; user
 *                                                   logins refuse, queued
 *                                                   tasks block until
 *                                                   reactivate
 *   - POST   /api/admin/tenants/{id}/reactivate    — flip back to
 *                                                   `active`
 *   - DELETE /api/admin/tenants/{id}               — soft delete (status
 *                                                   = `deleted` +
 *                                                   `deleted_at` =
 *                                                   NOW()). 30-day
 *                                                   recovery window
 *                                                   before
 *                                                   `pim:tenants:purge-deleted`
 *                                                   hard-deletes.
 *
 * Defaults per operator spec (epic 0.X RBAC marathon-3):
 *   - plan       = 'starter'
 *   - locales    = ['pl']
 *   - primary    = 'pl'
 *   - owner      = email supplied by operator; provisioned via
 *                  InvitationService so the password is set by the
 *                  Owner themselves (Mailpit catches in dev)
 *
 * Audit trail: every write wraps in
 * {@see SuperAdminContext::runCrossTenant()} so the existing
 * AuditLogListener stamps `cross_tenant_access=true` + `super_admin_id`
 * mechanically.
 */
final readonly class SuperAdminTenantWriteController
{
    private const string DEFAULT_PLAN = Tenant::PLAN_STARTER;

    public function __construct(
        private PlatformOperatorGuard $guard,
        private SuperAdminContext $superAdminContext,
        private TenantRepositoryInterface $tenants,
        private SuperAdminTenantResponseBuilder $builder,
        private SeedTenantPrdRolesService $seedRoles,
        private TenantCatalogBootstrap $bootstrapCatalog,
        private InvitationService $invitations,
        private ProvisioningQueue $provisioning,
    ) {
    }

    #[Route(path: '/api/admin/tenants', methods: ['POST'], name: 'api_admin_tenants_create')]
    #[RequiresPermission(module: 'platform.tenants', action: 'manage')]
    public function create(Request $request): JsonResponse
    {
        $caller = $this->guard->require(RbacMatrix::PERMISSION_PLATFORM_TENANTS_MANAGE);

        $payload = $this->decode($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $code = self::pickString($payload, 'code');
        $name = self::pickString($payload, 'name');
        $ownerEmail = self::pickString($payload, 'owner_email');
        $plan = self::pickString($payload, 'plan') ?? self::DEFAULT_PLAN;
        $domain = self::pickString($payload, 'domain');

        if (null === $code || null === $name || null === $ownerEmail) {
            return $this->problem(
                Response::HTTP_BAD_REQUEST,
                'Missing fields',
                '`code`, `name`, and `owner_email` are all required.',
            );
        }
        if (1 !== preg_match('/^[a-z0-9_-]{2,64}$/', $code)) {
            return $this->problem(
                Response::HTTP_BAD_REQUEST,
                'Invalid code',
                '`code` must be 2–64 chars of lowercase letters, digits, underscore, or hyphen.',
            );
        }
        if (!\in_array($plan, [Tenant::PLAN_STARTER, Tenant::PLAN_PRO, Tenant::PLAN_ENTERPRISE], true)) {
            return $this->problem(
                Response::HTTP_BAD_REQUEST,
                'Invalid plan',
                \sprintf('`plan` must be one of: %s.', implode(', ', [Tenant::PLAN_STARTER, Tenant::PLAN_PRO, Tenant::PLAN_ENTERPRISE])),
            );
        }

        // TNT-P4-05 (#2906) — subdomena adresuje instancję, więc jest polem
        // pierwszej klasy, a nie opcjonalną ozdobą. Domyślnie równa kodowi.
        $subdomainRaw = self::pickString($payload, 'subdomain') ?? $code;
        try {
            $subdomain = TenantSubdomain::fromString($subdomainRaw);
        } catch (InvalidArgumentException $exception) {
            return $this->problem(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'Invalid subdomain',
                $exception->getMessage(),
                ['code' => 'invalid_subdomain'],
            );
        }

        /** @var Tenant|JsonResponse $result */
        $result = $this->superAdminContext->runCrossTenant(
            $caller->getId(),
            function () use ($code, $name, $ownerEmail, $plan, $domain, $subdomain, $caller): Tenant|JsonResponse {
                if (null !== $this->tenants->findByCode($code)) {
                    return $this->problem(
                        Response::HTTP_CONFLICT,
                        'Duplicate code',
                        \sprintf('Tenant with code `%s` already exists.', $code),
                        ['code' => 'duplicate_code'],
                    );
                }

                $tenant = new Tenant(
                    code: $code,
                    name: $name,
                    domain: $domain ?? $subdomain->value,
                    plan: $plan,
                );

                // TNT-P4-05 (#2906 / ADR-0036) — ścieżka PANELOWA: platforma
                // nie tworzy tenanta u siebie, tylko odkłada zlecenie, a
                // instancja klienta powstaje osobno. Wiersz w rejestrze jest
                // od razu w stanie `provisioning`, bo instancji jeszcze nie ma
                // i nie wolno pokazać jej jako gotowej.
                //
                // Ścieżka lokalna (poniżej) zostaje dla dev/test i instalacji
                // jednoinstancyjnych, gdzie kolejki nie ma.
                if ($this->provisioning->isAvailable()) {
                    $tenant->markProvisioning();
                    $this->tenants->save($tenant);

                    $jobId = $this->provisioning->enqueueCreate(
                        tenant: $tenant,
                        subdomain: $subdomain,
                        ownerEmail: $ownerEmail,
                        requestedBy: $caller->getId(),
                    );

                    return $this->accepted($tenant, $jobId);
                }

                $this->tenants->save($tenant);

                // PRD-spec'd auto-seed: every new tenant gets the full
                // PRD §3.2 role catalogue scoped to its id. Idempotent
                // so a re-run from cortex:tenant:seed-roles is safe.
                $this->seedRoles->seed($tenant);

                // #2875 — built-in ObjectTypes, system + relation attributes
                // and the default sidebar. Before this the chain ran only in
                // AppFixtures, so a tenant created here came up with no
                // Product type and its owner was told to "run the catalog
                // seeder". Runs BEFORE the invitation on purpose: the owner
                // should land in a tenant that is already usable.
                $this->bootstrapCatalog->bootstrap($tenant);

                // Provision the default Owner via the existing
                // invitation flow — the email lands in Mailpit (dev) or
                // through the prod Mailer adapter, the recipient sets
                // the password through the accept-invitation page.
                $this->invitations->create(
                    tenant: $tenant,
                    email: $ownerEmail,
                    roleCode: 'tenant_owner',
                    invitedBy: $caller,
                );

                return $tenant;
            },
        );

        if ($result instanceof JsonResponse) {
            return $result;
        }

        return new JsonResponse($this->builder->buildOne($result), Response::HTTP_CREATED);
    }

    /**
     * Odpowiedź dla ścieżki panelowej: instancja jeszcze nie istnieje, więc
     * 202, nie 201. `job_id` pozwala pytać o postęp (#2907).
     */
    private function accepted(Tenant $tenant, string $jobId): JsonResponse
    {
        return new JsonResponse(
            $this->builder->buildOne($tenant) + ['provisioning_job_id' => $jobId],
            Response::HTTP_ACCEPTED,
        );
    }

    /**
     * Postęp provisioningu instancji. Panel odpytuje ten endpoint, dopóki
     * stan nie jest terminalny (`done`, `failed`, `rejected`).
     */
    #[Route(
        path: '/api/admin/tenants/provisioning/{jobId}',
        methods: ['GET'],
        name: 'api_admin_tenants_provisioning_status',
        requirements: ['jobId' => '[0-9a-f-]{36}'],
    )]
    #[RequiresPermission(module: 'platform.tenants', action: 'manage')]
    public function provisioningStatus(string $jobId): JsonResponse
    {
        $this->guard->require(RbacMatrix::PERMISSION_PLATFORM_TENANTS_MANAGE);

        $status = $this->provisioning->status($jobId);
        if (null === $status) {
            // Brak pliku statusu = provisioner jeszcze nie przejął zlecenia.
            // To NIE jest błąd: panel ma pokazać „w kolejce", a nie 404.
            return new JsonResponse(['job_id' => $jobId, 'state' => 'queued'], Response::HTTP_OK);
        }

        return new JsonResponse($status, Response::HTTP_OK);
    }

    #[Route(
        path: '/api/admin/tenants/{id}',
        methods: ['PATCH'],
        name: 'api_admin_tenants_update',
        requirements: ['id' => '[0-9a-f-]{36}'],
    )]
    #[RequiresPermission(module: 'platform.tenants', action: 'manage')]
    public function update(string $id, Request $request): JsonResponse
    {
        $caller = $this->guard->require(RbacMatrix::PERMISSION_PLATFORM_TENANTS_MANAGE);

        $payload = $this->decode($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        return $this->mutate(
            $caller,
            $id,
            function (Tenant $tenant) use ($payload): Tenant|JsonResponse {
                if (\array_key_exists('name', $payload)) {
                    $name = self::pickString($payload, 'name');
                    if (null === $name) {
                        return $this->problem(Response::HTTP_BAD_REQUEST, 'Invalid name', '`name` must be a non-empty string.');
                    }
                    $tenant->rename($name);
                }
                if (\array_key_exists('plan', $payload)) {
                    $plan = self::pickString($payload, 'plan');
                    if (null === $plan || !\in_array($plan, [Tenant::PLAN_STARTER, Tenant::PLAN_PRO, Tenant::PLAN_ENTERPRISE], true)) {
                        return $this->problem(Response::HTTP_BAD_REQUEST, 'Invalid plan', '`plan` must be a valid plan.');
                    }
                    // Plan change is a column flip per operator spec —
                    // no billing cascade, no quota enforcement in this
                    // phase. Tracked separately as future Stripe / quota
                    // work when billing leaves placeholder (#706 follow-up).
                    $tenant->changePlan($plan);
                }
                if (\array_key_exists('domain', $payload)) {
                    $domainRaw = $payload['domain'] ?? null;
                    if (null !== $domainRaw && !\is_string($domainRaw)) {
                        return $this->problem(Response::HTTP_BAD_REQUEST, 'Invalid domain', '`domain` must be a string or null.');
                    }
                    $tenant->changeDomain(\is_string($domainRaw) ? trim($domainRaw) : null);
                }

                return $tenant;
            },
        );
    }

    #[Route(
        path: '/api/admin/tenants/{id}/suspend',
        methods: ['POST'],
        name: 'api_admin_tenants_suspend',
        requirements: ['id' => '[0-9a-f-]{36}'],
    )]
    #[RequiresPermission(module: 'platform.tenants', action: 'manage')]
    public function suspend(string $id): JsonResponse
    {
        $caller = $this->guard->require(RbacMatrix::PERMISSION_PLATFORM_TENANTS_MANAGE);

        return $this->mutate(
            $caller,
            $id,
            static function (Tenant $tenant): Tenant {
                $tenant->suspend();

                return $tenant;
            },
        );
    }

    #[Route(
        path: '/api/admin/tenants/{id}/reactivate',
        methods: ['POST'],
        name: 'api_admin_tenants_reactivate',
        requirements: ['id' => '[0-9a-f-]{36}'],
    )]
    #[RequiresPermission(module: 'platform.tenants', action: 'manage')]
    public function reactivate(string $id): JsonResponse
    {
        $caller = $this->guard->require(RbacMatrix::PERMISSION_PLATFORM_TENANTS_MANAGE);

        return $this->mutate(
            $caller,
            $id,
            static function (Tenant $tenant): Tenant {
                $tenant->reactivate();

                return $tenant;
            },
        );
    }

    #[Route(
        path: '/api/admin/tenants/{id}',
        methods: ['DELETE'],
        name: 'api_admin_tenants_delete',
        requirements: ['id' => '[0-9a-f-]{36}'],
    )]
    #[RequiresPermission(module: 'platform.tenants', action: 'manage')]
    public function delete(string $id): JsonResponse
    {
        $caller = $this->guard->require(RbacMatrix::PERMISSION_PLATFORM_TENANTS_MANAGE);

        return $this->mutate(
            $caller,
            $id,
            static function (Tenant $tenant): Tenant {
                $tenant->softDelete();

                return $tenant;
            },
        );
    }

    /**
     * Shared mutation pipeline — load tenant by id under cross-tenant
     * mode, run the supplied closure, persist, project.
     *
     * @param callable(Tenant): (Tenant|JsonResponse) $mutator
     */
    private function mutate(User $caller, string $id, callable $mutator): JsonResponse
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (InvalidArgumentException) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'Not Found', 'Tenant not found.');
        }

        /** @var Tenant|JsonResponse $result */
        $result = $this->superAdminContext->runCrossTenant(
            $caller->getId(),
            function () use ($uuid, $mutator): Tenant|JsonResponse {
                $tenant = $this->tenants->findById($uuid);
                if (null === $tenant) {
                    return $this->problem(Response::HTTP_NOT_FOUND, 'Not Found', 'Tenant not found.');
                }
                $mutated = $mutator($tenant);
                if ($mutated instanceof JsonResponse) {
                    return $mutated;
                }
                $this->tenants->save($mutated);

                return $mutated;
            },
        );

        if ($result instanceof JsonResponse) {
            return $result;
        }

        return new JsonResponse($this->builder->buildOne($result));
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function decode(Request $request): array|JsonResponse
    {
        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'Bad Request', 'Request body must be JSON.');
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function pickString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;
        if (!\is_string($value)) {
            return null;
        }
        $value = trim($value);

        return '' === $value ? null : $value;
    }

    /**
     * @param array<string, mixed> $extras
     */
    private function problem(int $status, string $title, string $detail, array $extras = []): JsonResponse
    {
        return new JsonResponse(
            array_merge(
                [
                    'type' => 'about:blank',
                    'title' => $title,
                    'status' => $status,
                    'detail' => $detail,
                ],
                $extras,
            ),
            $status,
            ['Content-Type' => 'application/problem+json; charset=utf-8'],
        );
    }
}
