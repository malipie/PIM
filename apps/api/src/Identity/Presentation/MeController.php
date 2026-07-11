<?php

declare(strict_types=1);

namespace App\Identity\Presentation;

use App\Identity\Application\PermissionResolverInterface;
use App\Identity\Contracts\Attribute\NoPermissionRequired;
use App\Identity\Domain\Entity\User;
use DateTimeInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /api/auth/me — return the principal currently authenticated by the JWT.
 *
 * Used by the admin SPA on bootstrap to populate the identity store
 * (`useIdentity()` hook) and decide which sidebar entries / form fields
 * are reachable, and by integration clients smoke-testing their token.
 *
 * Response shape (after RBAC-P4-001 #678):
 *
 *   - `id`, `email`, `roles`            — Symfony Security role strings
 *                                          (legacy + scoped roles),
 *   - `tenant: {id, code, name}`        — caller's tenant header,
 *   - `last_login_at`                   — ATOM-formatted timestamp,
 *   - `permissions: string[]`           — flat PRD §3.2 permission codes
 *                                          aggregated from every assigned
 *                                          role (`products.view`,
 *                                          `settings.users.manage`, …),
 *   - `locale_scope: string[]`          — union of role locale scopes;
 *                                          `[]` / `["*"]` = no
 *                                          restriction (PRD §3.6),
 *   - `channel_scope: string[]`         — same for channels (PRD §3.7),
 *   - `attribute_group_scope: string[]` — group narrowing for the
 *                                          Modeler / channel-scoped
 *                                          roles.
 *
 * The frontend Set-backed identity store turns the permissions array
 * into O(1) `hasPermission(code)` lookups; locale / channel arrays
 * drive value-edit gating in the dynamic form renderer (RBAC-P4-009).
 */
final readonly class MeController
{
    public function __construct(
        private Security $security,
        private PermissionResolverInterface $resolver,
        private bool $workflowCustomDefinitionsEnabled = false,
    ) {
    }

    #[Route(path: '/api/auth/me', methods: ['GET'], name: 'api_auth_me')]
    #[NoPermissionRequired(reason: 'Every authenticated principal is allowed to read its own identity payload — no RBAC gate beyond JWT authentication.')]
    public function __invoke(): Response
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            // The JWT firewall guards `/api`, so reaching this controller
            // without a User principal would only happen in a misconfigured
            // environment — fall back to 401 rather than 500.
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

        $tenant = $user->getTenant();
        $permissions = $this->resolver->resolve($user);

        return new JsonResponse([
            'id' => $user->getId()->toRfc4122(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'tenant' => [
                'id' => $tenant->getId()->toRfc4122(),
                'code' => $tenant->getCode(),
                'name' => $tenant->getName(),
                // RBAC-P5-016 (#706) — Settings → Billing placeholder
                // surfaces the current plan tier read-only. Phase 1 ships
                // the actual billing integration; until then this field
                // is the only signal the placeholder page consumes.
                'plan' => $tenant->getPlan(),
            ],
            'last_login_at' => $user->getLastLoginAt()?->format(DateTimeInterface::ATOM),
            // Manual user creation (#867) — TRUE when an admin set the
            // password via POST /api/users. AuthedRoute on FE consumes this
            // flag and redirects to /first-login-password, blocking
            // navigation to the rest of the SPA until the user replaces
            // the admin-set password. Cleared by the change-password call.
            'password_change_required' => $user->isPasswordChangeRequired(),
            'permissions' => $permissions->getCodes(),
            'locale_scope' => $permissions->getLocaleScope(),
            'channel_scope' => $permissions->getChannelScope(),
            'attribute_group_scope' => $permissions->getAttributeGroupScope(),
            // WFL-P5-03 (#2433) — runtime feature flags the SPA cannot learn
            // at build time. Only tenant-agnostic deployment toggles belong
            // here; per-tenant entitlements stay on their own endpoints.
            'feature_flags' => [
                'workflow_custom_definitions' => $this->workflowCustomDefinitionsEnabled,
            ],
        ]);
    }
}
