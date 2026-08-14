<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Controller;

use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Domain\Entity\User;
use App\Shared\Domain\Tenant;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * RBAC-P5-015 (#705) — Settings → Tenant config endpoints.
 *
 *   - GET /api/tenant — returns the caller's tenant metadata.
 *   - PATCH /api/tenant — Owner-only rename / primary-locale change.
 *
 * Scope deviations from the ticket spec, documented in code:
 *   - Channels CRUD lives on `/api/channels` (already shipped); the
 *     Tenant config page does NOT duplicate it. The PRD §3.2 macierz
 *     line stays accurate — channels remain settings.channels.* gated.
 *   - Locales enable/disable left to a follow-up — the underlying
 *     `Tenant::enableLocale()` exists but the controller surface
 *     needs ISO 639-1 validation + reseed of role scope arrays
 *     (RBAC-P3-007 territory).
 *   - Tenant deletion (danger zone) deferred — it cascades to every
 *     domain table and needs a separate destructive-confirm flow.
 *
 * Permission gate: `user.admin` until Phase 6 retrofit migrates
 * onto PRD §3.2 `settings.tenant.manage`. The legacy code is held
 * only by super_admin / tenant_owner so the gate matches the
 * "Owner only" PRD §3.2 line in practice.
 */
final readonly class TenantConfigController
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $em,
    ) {
    }

    #[Route(path: '/api/tenant', methods: ['GET'], name: 'api_tenant_get')]
    #[RequiresPermission(module: 'user', action: 'admin', anyOf: ['user.admin', 'settings.tenant.manage'])]
    public function get(): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return $this->unauthorized();
        }

        return new JsonResponse($this->project($user->getTenant()));
    }

    #[Route(path: '/api/tenant', methods: ['PATCH'], name: 'api_tenant_patch')]
    #[RequiresPermission(module: 'user', action: 'admin', anyOf: ['user.admin', 'settings.tenant.manage'])]
    public function patch(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return $this->unauthorized();
        }

        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'Bad Request', 'Request body must be JSON.');
        }

        $tenant = $user->getTenant();

        if (\array_key_exists('name', $payload)) {
            $name = $payload['name'];
            if (!\is_string($name) || '' === trim($name)) {
                return $this->problem(Response::HTTP_BAD_REQUEST, 'Bad Request', '`name` must be a non-empty string.');
            }
            $tenant->rename(trim($name));
        }

        if (\array_key_exists('primary_locale', $payload)) {
            $primary = $payload['primary_locale'];
            if (!\is_string($primary)) {
                return $this->problem(Response::HTTP_BAD_REQUEST, 'Bad Request', '`primary_locale` must be a string.');
            }
            try {
                $tenant->changePrimaryLocale($primary);
            } catch (Throwable $e) {
                return $this->problem(
                    Response::HTTP_CONFLICT,
                    'Locale not enabled',
                    $e->getMessage(),
                );
            }
        }

        $this->em->flush();

        return new JsonResponse($this->project($tenant));
    }

    /**
     * @return array{
     *     id: string,
     *     code: string,
     *     name: string,
     *     plan: string,
     *     domain: ?string,
     *     enabled_locales: list<string>,
     *     primary_locale: string,
     *     created_at: string
     * }
     */
    private function project(Tenant $tenant): array
    {
        return [
            'id' => $tenant->getId()->toRfc4122(),
            'code' => $tenant->getCode(),
            'name' => $tenant->getName(),
            'plan' => $tenant->getPlan(),
            'domain' => $tenant->getDomain(),
            'enabled_locales' => $tenant->getEnabledLocales(),
            'primary_locale' => $tenant->getPrimaryLocale(),
            'created_at' => $tenant->getCreatedAt()->format(DateTimeInterface::ATOM),
        ];
    }

    private function unauthorized(): JsonResponse
    {
        return $this->problem(Response::HTTP_UNAUTHORIZED, 'Unauthorized', 'No authenticated user.');
    }

    private function problem(int $status, string $title, string $detail): JsonResponse
    {
        return new JsonResponse(
            [
                'type' => 'about:blank',
                'title' => $title,
                'status' => $status,
                'detail' => $detail,
            ],
            $status,
            ['Content-Type' => 'application/problem+json; charset=utf-8'],
        );
    }
}
