<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Entity\UserRole;
use App\Identity\Domain\Exception\DuplicateUserEmailException;
use App\Identity\Domain\Exception\RoleNotFoundException;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Identity\Domain\Repository\UserRoleRepositoryInterface;
use App\Shared\Domain\Tenant;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Manual user creation (#867) — alternative to the magic-link invitation
 * flow ({@see InvitationService}). Admin supplies email + password
 * directly; user is created as STATUS_ACTIVE with the
 * `passwordChangeRequired` flag toggled per the form checkbox.
 *
 * Flow:
 *   1. Resolve the role by code, scoped to the calling tenant.
 *   2. Reject if a user with the email already exists (409).
 *   3. Hash the admin-supplied password via UserPasswordHasherInterface.
 *   4. Create User (STATUS_ACTIVE, optional passwordChangeRequired flag).
 *   5. Assign role via UserRole junction.
 *   6. Optionally send a welcome email (silent on transport failure —
 *      admin can still hand over credentials out-of-band).
 *
 * Tenant scope: the controller must pass the calling user's Tenant; this
 * service does not resolve it itself so the flow stays composable for
 * Super-Admin cross-tenant tooling later.
 */
final class UserCreateService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly UserRoleRepositoryInterface $userRoles,
        private readonly RoleRepositoryInterface $roles,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'APP_BASE_URL')]
        private readonly string $appBaseUrl,
        #[Autowire(env: 'MAILER_FROM')]
        private readonly string $mailerFrom,
    ) {
    }

    /**
     * @throws DuplicateUserEmailException email already exists in tenant (→ 409)
     * @throws RoleNotFoundException       unknown role code (→ 400)
     */
    public function create(
        Tenant $tenant,
        string $email,
        ?string $displayName,
        string $roleCode,
        string $plaintextPassword,
        bool $forcePasswordChange,
        bool $sendWelcomeEmail,
        User $createdBy,
    ): User {
        $role = $this->roles->findByCode($roleCode, $tenant);
        if (null === $role) {
            throw RoleNotFoundException::forCode($roleCode);
        }

        if (null !== $this->users->findByEmail($email)) {
            throw DuplicateUserEmailException::forEmail($email);
        }

        // Two-step ctor dance — User has no setPassword(), only the
        // PasswordAuthenticatedUserInterface hook for hashing. Build a
        // throw-away instance for the hasher, then re-instantiate with the
        // hash + final flags. Same pattern as InvitationService::accept().
        $placeholder = new User(
            tenant: $tenant,
            email: $email,
            passwordHash: '',
            roles: ['ROLE_USER'],
            id: Uuid::v7(),
        );
        $hashed = $this->passwordHasher->hashPassword($placeholder, $plaintextPassword);
        $user = new User(
            tenant: $tenant,
            email: $email,
            passwordHash: $hashed,
            roles: ['ROLE_USER'],
            id: $placeholder->getId(),
            passwordChangeRequired: $forcePasswordChange,
        );

        // Two role-storage tables are kept in sync:
        //   - `user_roles` (M2M backing the `assignedRoles` collection) drives
        //     Symfony Security `getRoles()` and the `UserListResponseBuilder`
        //     projection (`$user->getAssignedRoles()`).
        //   - `user_role_assignments` (UserRole entity) carries the per-
        //     assignment scope columns (locale_scope, channel_scope) needed
        //     by Phase 3 voters and the PermissionResolver.
        // Both must be populated on create — otherwise the list view shows
        // the user with empty roles OR the scope guards miss the assignment.
        $user->addRole($role);
        $this->users->save($user);

        $userRole = new UserRole(
            userId: $user->getId(),
            roleId: $role->getId(),
        );
        $this->userRoles->save($userRole);

        if ($sendWelcomeEmail) {
            $this->sendWelcomeEmail(
                tenant: $tenant,
                recipientEmail: $email,
                displayName: $displayName,
                roleName: $role->getName(),
                adminEmail: $createdBy->getEmail(),
                forcePasswordChange: $forcePasswordChange,
            );
        }

        return $user;
    }

    private function sendWelcomeEmail(
        Tenant $tenant,
        string $recipientEmail,
        ?string $displayName,
        string $roleName,
        string $adminEmail,
        bool $forcePasswordChange,
    ): void {
        try {
            $message = new TemplatedEmail()
                ->from(new Address($this->mailerFrom, 'Harmon PIM'))
                ->to(new Address($recipientEmail))
                ->subject(\sprintf('Twoje konto PIM w %s jest gotowe', $tenant->getName()))
                ->htmlTemplate('email/user_welcome.html.twig')
                ->context([
                    'recipient_email' => $recipientEmail,
                    'display_name' => $displayName,
                    'tenant_name' => $tenant->getName(),
                    'role_name' => $roleName,
                    'admin_email' => $adminEmail,
                    'login_url' => \sprintf('%s/login', $this->appBaseUrl),
                    'force_password_change' => $forcePasswordChange,
                ]);
            $this->mailer->send($message);
        } catch (TransportExceptionInterface $e) {
            // Welcome email failure is not blocking — admin can still hand
            // the password over via Slack / in person. We log so an ops
            // alert can pick up systemic SMTP outages.
            $this->logger->warning('Welcome email failed to send', [
                'recipient' => $recipientEmail,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
