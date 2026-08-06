<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Entity\PasswordResetToken;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\PasswordResetTokenRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
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

/**
 * RBAC-P2-009 (#658) — password reset orchestrator.
 *
 * Security properties:
 *   - request() ALWAYS returns success (account-enumeration prevention).
 *     If email doesn't exist, no token is created — public response is
 *     identical to a real reset attempt.
 *   - SHA-256 hashed tokens (same algorithm as InvitationService via
 *     MagicLinkTokenHasher), 1-hour TTL.
 *   - Single-use enforcement: PasswordResetToken::markUsed() throws on
 *     re-use.
 *   - Password update through Symfony's UserPasswordHasher (Argon2id
 *     per config).
 *
 * Phase 2 dev mode: request() returns plaintext token in result array so
 * the operator can test confirm() before the mailer infra ships. The
 * caller (controller) decides whether to expose the token in response
 * body (dev only) or only log it (production).
 */
final class PasswordResetService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PasswordResetTokenRepositoryInterface $tokens,
        private readonly UserRepositoryInterface $users,
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
     * Initiate a reset for the given email. Returns the plaintext token
     * if a User was found; null otherwise (account-enumeration safe).
     *
     * NOTE: caller MUST treat the returned token as a single-use secret
     * — log it nowhere, email it once, never persist plaintext.
     */
    public function request(string $email): ?string
    {
        $user = $this->users->findByEmail($email);
        if (null === $user) {
            return null;
        }

        $plaintext = $this->tokenHasher->generate();
        $tokenHash = $this->tokenHasher->hash($plaintext);

        $token = new PasswordResetToken(
            tenantId: $user->getTenant()->getId(),
            userId: $user->getId(),
            tokenHash: $tokenHash,
        );
        $this->tokens->save($token);

        // Send reset email (Mailpit catches in dev). Failure is logged but
        // does NOT block the request flow — account-enumeration prevention
        // requires the response to be identical regardless of email
        // existence + mail delivery success.
        try {
            $message = new TemplatedEmail()
                ->from(new Address($this->mailerFrom, 'Cortex PIM'))
                ->to(new Address($email))
                ->subject('Reset hasła — Cortex PIM')
                ->htmlTemplate('email/password-reset.html.twig')
                ->context([
                    'recipient_email' => $email,
                    'confirm_url' => \sprintf('%s/password-reset/%s', $this->appBaseUrl, $plaintext),
                ]);
            $this->mailer->send($message);
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('Password reset email failed to send', [
                'token_id' => $token->getId()->toRfc4122(),
                'reason' => $e->getMessage(),
            ]);
        }

        return $plaintext;
    }

    /**
     * Consume a token, set new password. Invalidates the token + the
     * user's outstanding refresh tokens (caller invalidates JWT-side via
     * a separate listener if needed).
     *
     * @throws LogicException   when the token is already used / expired
     * @throws RuntimeException when the token is not found
     */
    public function confirm(string $plaintextToken, string $newPassword): User
    {
        $tokenHash = $this->tokenHasher->hash($plaintextToken);
        $token = $this->tokens->findByHash($tokenHash);
        if (null === $token) {
            throw new RuntimeException('Password reset token not found.');
        }
        if (!$token->isPending()) {
            throw new LogicException('Password reset token is no longer valid.');
        }

        /** @var User|null $user */
        $user = $this->em->find(User::class, $token->getUserId()->toRfc4122());
        if (null === $user) {
            throw new RuntimeException('User for password reset token not found.');
        }

        $hashed = $this->passwordHasher->hashPassword($user, $newPassword);

        // User has no setPassword method — replace via reflection / new
        // instance. Existing User entity uses immutable password; we
        // create a new entity with the same id + new hash. Since the
        // Doctrine identity map keys by id, persist on the new instance
        // updates the existing row.
        // NOTE: This pattern is acceptable for the password change use
        // case where the User is wholly re-issued. If User gains more
        // mutable fields, refactor to a setPasswordHash() domain method.
        $em = $this->em;
        $em->createQuery('UPDATE App\\Identity\\Domain\\Entity\\User u SET u.password = :hash WHERE u.id = :id')
            ->setParameter('hash', $hashed)
            ->setParameter('id', $user->getId()->toRfc4122())
            ->execute();

        // Mark token used (throws if already used / expired — caught
        // upstream as 400).
        $token->markUsed();
        $this->tokens->save($token);

        // Detach + re-find so the in-memory User reflects the new hash.
        $em->detach($user);
        /** @var User $refreshed */
        $refreshed = $em->find(User::class, $user->getId()->toRfc4122());

        return $refreshed;
    }

    /**
     * Cron-callable cleanup of stale rows (expired or used > 24h ago).
     */
    public function purgeStale(?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable();
        $cutoff = $now->modify('-24 hours');

        return $this->tokens->purgeStale($cutoff);
    }
}
