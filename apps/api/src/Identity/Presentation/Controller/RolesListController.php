<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Controller;

use App\Identity\Application\RoleListResponseBuilder;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RBAC-P5-005 (#695) — `GET /api/roles` listing for Settings → Roles.
 *
 * Returns every global (system) role plus the caller tenant's custom
 * roles, paired with the number of users in that tenant who currently
 * hold each role. System roles surface as `type: "system"` with
 * `is_built_in: true`; custom roles surface as `type: "custom"`.
 *
 * The response is wrapped in the same Hydra-compatible envelope used
 * by {@see UsersListController} so the admin data-provider unwraps
 * `member` / `totalItems` consistently across Settings pages.
 */
final readonly class RolesListController
{
    public function __construct(
        private Security $security,
        private RoleRepositoryInterface $roles,
        private RoleListResponseBuilder $builder,
    ) {
    }

    #[Route(path: '/api/roles', methods: ['GET'], name: 'api_roles_list')]
    /*
     * Same `user.admin` gate as the Users list — Settings → Roles is part
     * of the same Settings → Users management surface. Phase 6 retrofit
     * (#720+) migrates onto PRD §3.2 `settings.roles.manage`.
     */
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

        $rows = $this->roles->findAllForTenantWithUserCount($principal->getTenant());
        $member = $this->builder->buildList($rows);

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
