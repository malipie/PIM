<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Controller;

use App\Identity\Application\UserCreateService;
use App\Identity\Application\UserListResponseBuilder;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Exception\DuplicateUserEmailException;
use App\Identity\Domain\Exception\RoleNotFoundException;
use App\Shared\Application\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Manual user creation (#867) — `POST /api/users`. Alternative to the
 * magic-link invitation flow ({@see InvitationController::create}). Admin
 * supplies email + password directly; user is created STATUS_ACTIVE.
 *
 * Payload:
 * {
 *   "email": "ada@example.com",
 *   "display_name": "Ada Kowalska",     // optional
 *   "role_code": "catalog_manager",
 *   "password": "min-12-chars",
 *   "force_password_change": true,        // optional, default true
 *   "send_welcome_email": true            // optional, default true
 * }
 *
 * Responses:
 *   201 + UserListItem projection — happy path
 *   400 — validation error or unknown role_code
 *   403 — caller lacks settings.users.manage
 *   409 — email already exists in tenant
 *
 * Permission: same `settings.users.manage` as invitations (KISS — split
 * is a follow-up if operators want to gate the two flows separately).
 */
final class UserCreateController extends AbstractController
{
    public function __construct(
        private readonly UserCreateService $createService,
        private readonly UserListResponseBuilder $responseBuilder,
        private readonly TenantContext $tenantContext,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route(path: '/api/users', methods: ['POST'], name: 'api_users_create')]
    /*
     * Permission gate: `user.admin` from the seeded RbacMatrix — same code
     * as UsersListController + UserDeactivationController, so a single
     * voter call gates the whole CRUD surface. Phase 6 (#720+) retrofits
     * every users endpoint onto `settings.users.manage` per PRD §3.2 — the
     * gate moves there in a batch then.
     */
    #[RequiresPermission(module: 'user', action: 'admin', anyOf: ['user.admin', 'settings.users.manage'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function create(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new BadRequestHttpException('Tenant context required.');
        }

        /** @var User|null $caller */
        $caller = $this->getUser();
        if (!$caller instanceof User) {
            throw new BadRequestHttpException('User principal required (JWT auth).');
        }

        $rawPayload = json_decode($request->getContent(), true);
        if (!\is_array($rawPayload)) {
            throw new BadRequestHttpException('Request body must be JSON.');
        }

        $email = trim(\is_string($rawPayload['email'] ?? null) ? $rawPayload['email'] : '');
        $displayName = \is_string($rawPayload['display_name'] ?? null)
            ? trim($rawPayload['display_name'])
            : null;
        if (null !== $displayName && '' === $displayName) {
            $displayName = null;
        }
        $roleCode = trim(\is_string($rawPayload['role_code'] ?? null) ? $rawPayload['role_code'] : '');
        $password = \is_string($rawPayload['password'] ?? null) ? $rawPayload['password'] : '';
        $forcePasswordChange = \array_key_exists('force_password_change', $rawPayload)
            ? (bool) $rawPayload['force_password_change']
            : true;
        $sendWelcomeEmail = \array_key_exists('send_welcome_email', $rawPayload)
            ? (bool) $rawPayload['send_welcome_email']
            : true;

        $violations = $this->validator->validate(
            [
                'email' => $email,
                'role_code' => $roleCode,
                'password' => $password,
            ],
            new Assert\Collection([
                'email' => [new Assert\NotBlank(), new Assert\Email()],
                'role_code' => [new Assert\NotBlank()],
                'password' => [
                    new Assert\NotBlank(),
                    new Assert\Length(min: 12, minMessage: 'Password must be at least {{ limit }} characters.'),
                ],
            ]),
        );
        if (\count($violations) > 0) {
            $first = $violations->get(0);

            throw new BadRequestHttpException((string) $first->getMessage());
        }

        try {
            $user = $this->createService->create(
                tenant: $tenant,
                email: $email,
                displayName: $displayName,
                roleCode: $roleCode,
                plaintextPassword: $password,
                forcePasswordChange: $forcePasswordChange,
                sendWelcomeEmail: $sendWelcomeEmail,
                createdBy: $caller,
            );
        } catch (DuplicateUserEmailException $e) {
            throw new ConflictHttpException($e->getMessage(), $e);
        } catch (RoleNotFoundException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }

        return new JsonResponse($this->responseBuilder->buildOne($user), Response::HTTP_CREATED);
    }
}
