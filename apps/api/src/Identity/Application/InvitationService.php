<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Entity\Invitation;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\InvitationRepositoryInterface;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

use const DATE_ATOM;

/**
 * RBAC-P2-008 (#657) — magic-link invitation orchestrator.
 *
 * Flow:
 *   create(): generate 32-byte random token → SHA-256 hash to DB →
 *             return Invitation + plaintext token (single in-transit copy).
 *             Phase 2 ships token in API response (dev mode); production
 *             email send via Symfony Mailer is a follow-up ticket (mailer
 *             infra not yet configured in repo).
 *   accept(): hash incoming token → query by token_hash (unique index) →
 *             verify Invitation::isPending() → create User + role
 *             assignment → mark Invitation::accept().
 *   revoke(): sets revokedAt via Invitation::revoke().
 *
 * Single-use enforcement: Invitation::accept() throws LogicException if
 * acceptedAt non-null. The first acceptance wins; subsequent attempts
 * with the same token surface as 409.
 *
 * Tenant scope: every operation requires the calling user's TenantContext
 * to match the Invitation tenant_id. The repository layer does not
 * automatically enforce this — the controller MUST pass Tenant explicitly.
 */
final class InvitationService
{
    private const int TTL_DAYS = 7;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InvitationRepositoryInterface $invitations,
        private readonly UserRepositoryInterface $users,
        private readonly RoleRepositoryInterface $roles,
        private readonly MagicLinkTokenHasher $tokenHasher,
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
     * Create a pending invitation. Returns the Invitation entity + the
     * plaintext token (the caller MUST treat this as a single-use secret
     * — log it nowhere, email it once, never persist plaintext).
     *
     * @return array{invitation: Invitation, token: string}
     */
    public function create(
        Tenant $tenant,
        string $email,
        string $roleCode,
        User $invitedBy,
    ): array {
        $role = $this->roles->findByCode($roleCode, $tenant);
        if (null === $role) {
            throw new RuntimeException(\sprintf('Role "%s" not found in tenant "%s".', $roleCode, $tenant->getCode()));
        }

        $plaintext = $this->tokenHasher->generate();
        $tokenHash = $this->tokenHasher->hash($plaintext);
        $expiresAt = new DateTimeImmutable(\sprintf('+%d days', self::TTL_DAYS));

        $invitation = new Invitation(
            tenantId: $tenant->getId(),
            email: $email,
            tokenHash: $tokenHash,
            invitedByUserId: $invitedBy->getId(),
            roleId: $role->getId(),
            expiresAt: $expiresAt,
        );

        $this->invitations->save($invitation);

        // Send invitation email (Mailpit catches in dev — see https://mail.pim.localhost).
        // Failure to send is logged but does NOT block the create flow — the
        // operator can re-send via Phase 5 UI, and the dev-mode token return
        // covers test scenarios.
        try {
            $email = new TemplatedEmail()
                ->from(new Address($this->mailerFrom, 'Harmon PIM'))
                ->to(new Address($email))
                ->subject(\sprintf('Zaproszenie do %s — Harmon PIM', $tenant->getName()))
                ->htmlTemplate('email/invitation.html.twig')
                ->context([
                    'recipient_email' => $email,
                    'tenant_name' => $tenant->getName(),
                    'invited_by_email' => $invitedBy->getEmail(),
                    'role_name' => $role->getName(),
                    // #2827 — the link MUST address the admin SPA route that
                    // actually exists (`/accept-invitation?token=…`, App.tsx).
                    // The previous `/invitations/{token}/accept` looked like the
                    // API path but nothing served it: Caddy hands every non-/api
                    // path to the SPA, whose catch-all route redirects to
                    // /dashboard and — with no session — on to /login. The
                    // invitee saw a login screen and the token was lost.
                    'accept_url' => \sprintf('%s/accept-invitation?token=%s', $this->appBaseUrl, $plaintext),
                    'expires_at' => $expiresAt,
                ]);
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('Invitation email failed to send', [
                'invitation_id' => $invitation->getId()->toRfc4122(),
                'reason' => $e->getMessage(),
            ]);
        }

        return ['invitation' => $invitation, 'token' => $plaintext];
    }

    /**
     * Accept the invitation, creating a User + role assignment.
     *
     * @throws LogicException when the invitation is already accepted /
     *                        revoked / expired (Invitation::accept enforces)
     */
    public function accept(string $plaintextToken, string $newPassword): User
    {
        $tokenHash = $this->tokenHasher->hash($plaintextToken);
        $invitation = $this->invitations->findByHash($tokenHash);
        if (null === $invitation) {
            throw new RuntimeException('Invitation token not found.');
        }

        // Resolve tenant via the existing EntityManager (filter-aware).
        /** @var Tenant|null $tenant */
        $tenant = $this->em->find(Tenant::class, $invitation->getTenantId()->toRfc4122());
        if (null === $tenant) {
            throw new RuntimeException('Invitation tenant not found.');
        }

        // AUD-024 / W1-12: validate + consume the invitation state BEFORE any
        // write. Previously the User was inserted first and `accept()` ran
        // last, so a second use of an already-accepted token hit the
        // users_email_uniq constraint (500 instead of 400) and an EXPIRED
        // invitation still created a usable account before the expiry guard
        // threw. Marking the invitation consumed up-front makes a stale token
        // (already-accepted / revoked / expired) surface as a 400 with no
        // user/role side effect.
        // Stan zaproszenia sprawdzany PRZED jakimkolwiek zapisem. To jest ta
        // sama ochrona, którą wprowadziło AUD-024 (ponowne użycie tokenu ma dać
        // 400, nie 500) — tyle że jako jawne sprawdzenie, a nie jako efekt
        // uboczny wcześniejszego zapisu. Dzięki temu drugie kliknięcie
        // w zużyty link nie zmienia hasła i nie dotyka konta.
        if ($invitation->isAccepted()) {
            throw new LogicException('Invitation already accepted.');
        }
        if ($invitation->isRevoked()) {
            throw new LogicException('Invitation revoked.');
        }
        if ($invitation->isExpired()) {
            throw new LogicException('Invitation expired.');
        }

        // Rola musi istnieć ZANIM cokolwiek zapiszemy — inaczej zaproszenie
        // zostaje zużyte, a użytkownik bez roli, do której był zapraszany.
        $invitedRole = $this->roles->findById($invitation->getRoleId());
        if (null === $invitedRole) {
            throw new RuntimeException('Role attached to the invitation no longer exists.');
        }

        // Konto MOŻE już istnieć — i przy tenancie zakładanym z panelu istnieje
        // ZAWSZE. `pim:tenant:bootstrap` tworzy właściciela z hasłem tymczasowym,
        // żeby instancja w ogóle wstała i przeszła smoke test, a zaproszenie jest
        // sposobem, w jaki właściciel przejmuje to konto i ustawia własne hasło.
        //
        // Wstawianie drugiego użytkownika kończyło się `users_email_uniq`
        // i błędem 500 — przy JEDNOCZEŚNIE zużytym już zaproszeniu, bo stan
        // zaproszenia zapisywany był wcześniej. Token przepadał, hasło zostawało
        // tymczasowe i losowe, więc nikt nie mógł wejść do świeżej instancji.
        $existing = $this->users->findByEmail($invitation->getEmail());
        $sameTenant = null !== $existing
            && $existing->getTenant()->getId()->equals($tenant->getId());

        // Adres zajęty przez konto z INNEGO tenanta w tej samej bazie. Kolumna
        // `users.email` ma unikalność globalną, nie per tenant, więc wstawienie
        // i tak by się nie udało — tyle że jako 500 z surowego błędu bazy.
        // Odrzucamy przed zapisem, żeby zaproszenie zostało nietknięte i dało
        // się je wystawić na inny adres.
        if (null !== $existing && !$sameTenant) {
            throw new LogicException('An account with this email already exists in another tenant.');
        }

        $user = $sameTenant ? $existing : new User(
            tenant: $tenant,
            email: $invitation->getEmail(),
            passwordHash: '', // hash ustawiany niżej — konstruktor wymaga wartości
            roles: ['ROLE_USER'],
            id: Uuid::v7(),
        );

        $user->changePassword($this->passwordHasher->hashPassword($user, $newPassword));
        $user->addRole($invitedRole);

        // Zaproszenie znaczone jako zużyte DOPIERO po udanym zapisie konta.
        // Odwrotna kolejność (AUD-024) chroniła przed ponownym użyciem tokenu,
        // ale zamieniała każdą awarię zapisu w bezpowrotną utratę zaproszenia.
        // Ochronę przed ponowieniem daje sprawdzenie stanu wyżej, a spójność —
        // jedna transakcja obejmująca oba zapisy.
        $this->em->wrapInTransaction(function () use ($user, $invitation): void {
            $this->users->save($user);
            $invitation->accept();
            $this->invitations->save($invitation);
        });

        return $user;
    }

    /**
     * RBAC-P5-017 (#707) — read-only inspect for the magic-link accept
     * page. Returns a snapshot of the invitation state so the FE can
     * branch between "show password form" / "already accepted" /
     * "revoked or expired" without leaking enough metadata to enumerate
     * other invitations: only `status`, the target email and the
     * inviting tenant's display name make it across.
     *
     * Always returns a status — null token / no match collapses into
     * `not_found` so the controller surface stays uniform.
     *
     * @return array{
     *     status: 'valid'|'expired'|'accepted'|'revoked'|'not_found',
     *     email?: string,
     *     tenant_name?: string,
     *     expires_at?: string
     * }
     */
    public function verify(string $plaintextToken): array
    {
        if ('' === $plaintextToken) {
            return ['status' => 'not_found'];
        }

        $tokenHash = $this->tokenHasher->hash($plaintextToken);
        $invitation = $this->invitations->findByHash($tokenHash);
        if (null === $invitation) {
            return ['status' => 'not_found'];
        }

        /** @var Tenant|null $tenant */
        $tenant = $this->em->find(Tenant::class, $invitation->getTenantId()->toRfc4122());
        $tenantName = null !== $tenant ? $tenant->getName() : '';

        $base = [
            'email' => $invitation->getEmail(),
            'tenant_name' => $tenantName,
            'expires_at' => $invitation->getExpiresAt()->format(DATE_ATOM),
        ];

        if ($invitation->isAccepted()) {
            return ['status' => 'accepted', ...$base];
        }
        if ($invitation->isRevoked()) {
            return ['status' => 'revoked', ...$base];
        }
        if ($invitation->getExpiresAt() < new DateTimeImmutable()) {
            return ['status' => 'expired', ...$base];
        }

        return ['status' => 'valid', ...$base];
    }

    public function revoke(Uuid $invitationId): void
    {
        $invitation = $this->invitations->findById($invitationId);
        if (null === $invitation) {
            throw new RuntimeException('Invitation not found.');
        }
        $invitation->revoke();
        $this->invitations->save($invitation);
    }
}
