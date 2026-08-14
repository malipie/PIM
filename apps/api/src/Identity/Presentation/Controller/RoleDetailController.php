<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Controller;

use App\Identity\Application\RoleDetailResponseBuilder;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * RBAC-P5-006 (#696) — `GET /api/roles/{id}` returns a single role
 * with its permission codes pre-resolved, so the custom-role builder
 * UI can pre-check the matrix cells in one round trip.
 *
 * Cross-tenant safety: the role is reachable when it's either global
 * (`tenant IS NULL`) or scoped to the caller's tenant. Custom roles
 * from other tenants surface as 404 — the same NotFound shape used
 * for nonexistent ids, so an attacker cannot probe id existence.
 */
final readonly class RoleDetailController
{
    public function __construct(
        private Security $security,
        private RoleRepositoryInterface $roles,
        private RoleDetailResponseBuilder $builder,
    ) {
    }

    #[Route(path: '/api/roles/{id}', methods: ['GET'], name: 'api_roles_detail', requirements: ['id' => '[0-9a-f-]{36}'])]
    #[RequiresPermission(module: 'user', action: 'admin', anyOf: ['user.admin', 'settings.roles.manage'])]
    public function __invoke(string $id): JsonResponse
    {
        $caller = $this->security->getUser();
        if (!$caller instanceof User) {
            return $this->problem(Response::HTTP_UNAUTHORIZED, 'Unauthorized', 'No authenticated user.');
        }

        try {
            $uuid = Uuid::fromString($id);
        } catch (InvalidArgumentException) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'Not Found', 'Role not found.');
        }

        $role = $this->roles->findById($uuid);
        if (null === $role) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'Not Found', 'Role not found.');
        }

        if (!$role->isGlobal()) {
            $roleTenant = $role->getTenant();
            if (null === $roleTenant || !$caller->getTenant()->getId()->equals($roleTenant->getId())) {
                return $this->problem(Response::HTTP_NOT_FOUND, 'Not Found', 'Role not found.');
            }
        }

        return new JsonResponse($this->builder->buildOne($role));
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
