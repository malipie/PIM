<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Controller;

use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\PermissionRepositoryInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RBAC-P5-006 (#696) — `GET /api/permissions` returns the catalogue of
 * atomic permissions seeded by {@see \App\DataFixtures\Identity\PrdPermissionFixtures},
 * grouped by PRD-PIM-rbac §3.2 module for the custom-role-builder
 * matrix.
 *
 * Wire shape — single Hydra-style envelope so the admin data-provider
 * unwraps without a custom branch:
 *
 *   {
 *     "member": [
 *       {
 *         "module":      "products",            // top-level group label
 *         "module_label": "Produkty",           // localised by FE i18n
 *         "permissions": [
 *           { "id": "uuid", "code": "products.view",   "action": "view"   },
 *           { "id": "uuid", "code": "products.add",    "action": "add"    },
 *           ...
 *         ]
 *       },
 *       ...
 *     ],
 *     "totalItems": <int>,
 *     "meta": { ... }
 *   }
 *
 * The module label resolution stays client-side — the backend exposes
 * the canonical PRD code (`products`, `categories`, `multimedia`, ...)
 * and the FE catalogue maps it onto i18n strings, so adding a locale
 * does not require a backend deploy.
 *
 * Permission gate: `user.admin` like the rest of the Settings surface
 * (#691, #695, #699). Phase 6 retrofit (#720+) migrates onto PRD
 * `settings.roles.manage`.
 */
final readonly class PermissionsListController
{
    public function __construct(
        private Security $security,
        private PermissionRepositoryInterface $permissions,
    ) {
    }

    #[Route(path: '/api/permissions', methods: ['GET'], name: 'api_permissions_list')]
    #[RequiresPermission(module: 'user', action: 'admin', anyOf: ['user.admin', 'settings.roles.manage'])]
    public function __invoke(): JsonResponse
    {
        $principal = $this->security->getUser();
        if (!$principal instanceof User) {
            return new JsonResponse(
                [
                    'type' => 'about:blank',
                    'title' => 'Unauthorized',
                    'status' => Response::HTTP_UNAUTHORIZED,
                    'detail' => 'No authenticated user.',
                ],
                Response::HTTP_UNAUTHORIZED,
                ['Content-Type' => 'application/problem+json; charset=utf-8'],
            );
        }

        $rows = $this->permissions->findAllOrdered();
        // Bucket permissions by their PRD module prefix — the segment
        // before the last dot. `settings.users.manage` → `settings.users`,
        // `products.view` → `products`. Each bucket preserves seed order
        // because PrdPermissionFixtures already orders by PRD §3.2 layout.
        $buckets = [];
        foreach ($rows as $permission) {
            $code = $permission->getCode();
            $lastDot = strrpos($code, '.');
            $module = false === $lastDot ? $code : substr($code, 0, $lastDot);
            $action = false === $lastDot ? '' : substr($code, $lastDot + 1);

            if (!isset($buckets[$module])) {
                $buckets[$module] = [];
            }
            $buckets[$module][] = [
                'id' => $permission->getId()->toRfc4122(),
                'code' => $code,
                'action' => $action,
            ];
        }

        $member = [];
        foreach ($buckets as $module => $permissions) {
            $member[] = [
                'module' => $module,
                'permissions' => $permissions,
            ];
        }

        return new JsonResponse([
            'member' => $member,
            'totalItems' => \count($member),
            'meta' => [
                'page' => 1,
                'per_page' => \count($member),
                'total_pages' => 1,
            ],
        ]);
    }
}
