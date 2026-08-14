<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Controller;

use App\Catalog\Contracts\Service\AttributeCatalogReader;
use App\Identity\Application\RoleAttributePermissionResponseBuilder;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Domain\Entity\Role;
use App\Identity\Domain\Entity\RoleAttributePermission;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\RoleAttributePermissionRepositoryInterface;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * RBAC-P5-007 (#697) — endpoints for per-attribute permission overrides
 * on a role.
 *
 *   - GET /api/roles/{id}/attribute-permissions
 *     Returns the full attribute catalogue for the caller's tenant,
 *     bucketed by AttributeGroup, with each attribute's current
 *     override level for this role (null = no override → falls back
 *     to the role's matrix grant from #696).
 *
 *   - PUT /api/roles/{id}/attribute-permissions
 *     Replaces the entire override set for the role in one
 *     transactional sweep. Body shape:
 *       { "attribute_permissions": [
 *           { "attribute_id": "uuid", "permission_level": "view|edit|restricted" },
 *           ...
 *       ] }
 *     Omitted attribute ids drop their override row (resolver falls
 *     back to the matrix). Cross-tenant attribute ids surface as 400
 *     ("Unknown attribute id").
 *
 * Identity reads Catalog metadata through {@see AttributeCatalogReader}
 * (Catalog_Contracts layer) to honour the BC fence — Identity does NOT
 * import `App\Catalog\Domain\Repository\AttributeRepositoryInterface`.
 *
 * Permission gate: `user.admin` per Phase 6 retrofit (#720+) plan —
 * the consumer side (a Phase 3 AttributePermissionPolicy that reads
 * these rows during read/write voting) is wired separately.
 */
final readonly class RoleAttributePermissionsController
{
    public function __construct(
        private Security $security,
        private RoleRepositoryInterface $roles,
        private AttributeCatalogReader $attributeReader,
        private RoleAttributePermissionRepositoryInterface $overrides,
        private RoleAttributePermissionResponseBuilder $builder,
    ) {
    }

    #[Route(
        path: '/api/roles/{id}/attribute-permissions',
        methods: ['GET'],
        name: 'api_roles_attribute_permissions_get',
        requirements: ['id' => '[0-9a-f-]{36}'],
    )]
    #[RequiresPermission(module: 'user', action: 'admin', anyOf: ['user.admin', 'settings.roles.manage'])]
    public function get(string $id): JsonResponse
    {
        $caller = $this->security->getUser();
        if (!$caller instanceof User) {
            return $this->problem(Response::HTTP_UNAUTHORIZED, 'Unauthorized', 'No authenticated user.');
        }

        $role = $this->loadAccessibleRole($caller, $id);
        if ($role instanceof JsonResponse) {
            return $role;
        }

        $attributes = $this->attributeReader->findAllByTenant($caller->getTenant()->getId());
        $overrides = $this->overrides->findByRole($role->getId());

        return new JsonResponse($this->builder->build($role->getId()->toRfc4122(), $attributes, $overrides));
    }

    #[Route(
        path: '/api/roles/{id}/attribute-permissions',
        methods: ['PUT'],
        name: 'api_roles_attribute_permissions_replace',
        requirements: ['id' => '[0-9a-f-]{36}'],
    )]
    #[RequiresPermission(module: 'user', action: 'admin', anyOf: ['user.admin', 'settings.roles.manage'])]
    public function replace(string $id, Request $request): JsonResponse
    {
        $caller = $this->security->getUser();
        if (!$caller instanceof User) {
            return $this->problem(Response::HTTP_UNAUTHORIZED, 'Unauthorized', 'No authenticated user.');
        }

        $role = $this->loadAccessibleRole($caller, $id);
        if ($role instanceof JsonResponse) {
            return $role;
        }

        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'Bad Request', 'Request body must be JSON.');
        }

        $items = $payload['attribute_permissions'] ?? null;
        if (!\is_array($items)) {
            return $this->problem(
                Response::HTTP_BAD_REQUEST,
                'Bad Request',
                '`attribute_permissions` must be an array.',
            );
        }

        $tenantId = $caller->getTenant()->getId();
        $next = [];
        $seenAttrIds = [];

        foreach ($items as $rawItem) {
            if (!\is_array($rawItem)) {
                return $this->problem(
                    Response::HTTP_BAD_REQUEST,
                    'Bad Request',
                    'Each `attribute_permissions` entry must be an object.',
                );
            }
            $rawId = $rawItem['attribute_id'] ?? null;
            $rawLevel = $rawItem['permission_level'] ?? null;
            if (!\is_string($rawId) || !\is_string($rawLevel)) {
                return $this->problem(
                    Response::HTTP_BAD_REQUEST,
                    'Bad Request',
                    'Each entry needs `attribute_id` (UUID string) and `permission_level` (string).',
                );
            }

            try {
                $attrUuid = Uuid::fromString($rawId);
            } catch (InvalidArgumentException) {
                return $this->problem(
                    Response::HTTP_BAD_REQUEST,
                    'Bad Request',
                    \sprintf('Invalid attribute id `%s`.', $rawId),
                );
            }

            if (isset($seenAttrIds[$attrUuid->toRfc4122()])) {
                return $this->problem(
                    Response::HTTP_BAD_REQUEST,
                    'Bad Request',
                    \sprintf('Duplicate `attribute_id` `%s` in payload.', $rawId),
                );
            }
            $seenAttrIds[$attrUuid->toRfc4122()] = true;

            $summary = $this->attributeReader->findOnTenant($attrUuid, $tenantId);
            if (null === $summary) {
                return $this->problem(
                    Response::HTTP_BAD_REQUEST,
                    'Bad Request',
                    \sprintf('Unknown attribute id `%s`.', $rawId),
                );
            }

            try {
                $next[] = new RoleAttributePermission(
                    roleId: $role->getId(),
                    attributeId: $attrUuid,
                    permissionLevel: $rawLevel,
                );
            } catch (InvalidArgumentException $e) {
                return $this->problem(Response::HTTP_BAD_REQUEST, 'Bad Request', $e->getMessage());
            }
        }

        $this->overrides->replaceForRole($role->getId(), $next);

        // Re-read the fresh set so the response reflects the persisted
        // state (avoids drift if a listener mutated anything async).
        $persisted = $this->overrides->findByRole($role->getId());
        $attributes = $this->attributeReader->findAllByTenant($tenantId);

        return new JsonResponse($this->builder->build($role->getId()->toRfc4122(), $attributes, $persisted));
    }

    private function loadAccessibleRole(User $caller, string $id): Role|JsonResponse
    {
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

        return $role;
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
