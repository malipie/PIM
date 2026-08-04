<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Entity\User;
use App\Shared\Application\Crypto\SecretCipher;
use Doctrine\ORM\EntityManagerInterface;
use OTPHP\TOTP;
use OTPHP\TOTPInterface;
use ParagonIE\ConstantTime\Base32;

use const PASSWORD_ARGON2ID;

/**
 * Application service that owns the 2FA TOTP enrolment lifecycle (#0.11.1).
 *
 * One instance handles three flows:
 *   - `enrol()`     — provisions a fresh TOTP secret + 10 single-use
 *                     backup codes; returns the cleartext secret + the
 *                     `otpauth://` URI an authenticator app scans.
 *   - `confirm()`   — validates the first code from the user's app and
 *                     flips the user into "2FA active".
 *   - `disable()`   — wipes the secret + recovery codes after the user
 *                     proves possession of the device.
 *
 * The service does NOT couple to Symfony Security; the controllers
 * resolve the authenticated `User` and pass it in. Recovery codes are
 * hashed with the standard Symfony password hasher (Argon2id by
 * default) so a database leak never exposes them in cleartext.
 *
 * #2726 — the TOTP secret is encrypted at rest through {@see SecretCipher}.
 * It cannot be hashed like the recovery codes: TOTP verification needs the
 * secret back, so a leaked `users` dump (backup, replica) would otherwise let
 * an attacker mint valid second factors for every enrolled user and MFA would
 * be decorative. Every read goes through the cipher, which passes pre-#2726
 * plaintext rows through unchanged so enrolments migrate on their next write.
 */
final readonly class TotpEnrolmentService
{
    public const string ISSUER = 'PIM';

    /**
     * Number of one-shot recovery codes minted at enrolment + each
     * rotate. The rest of the industry settled on 8–12; 10 is the
     * shape Symfony Authenticator itself surfaces.
     */
    public const int BACKUP_CODE_COUNT = 10;

    public function __construct(
        private EntityManagerInterface $em,
        private SecretCipher $cipher,
    ) {
    }

    /**
     * Allocate fresh secret + recovery codes on the user. The user
     * remains in "2FA pending" state — `isTotpEnabled()` stays false
     * until {@see confirm()} accepts the first generated code.
     *
     * Returns the cleartext recovery codes ONLY ONCE (caller surfaces
     * them in the response, then they are gone — only the hashes
     * persist).
     *
     * @return array{secret: string, provisioning_uri: string, backup_codes: list<string>}
     */
    public function enrol(User $user): array
    {
        $secret = self::generateSecret();
        $totp = self::makeTotp($secret, $user);

        [$cleartextCodes, $hashedCodes] = $this->generateBackupCodes();

        $user->startTotpEnrolment($this->cipher->protect($secret), $hashedCodes);
        $this->em->flush();

        return [
            'secret' => $secret,
            'provisioning_uri' => $totp->getProvisioningUri(),
            'backup_codes' => $cleartextCodes,
        ];
    }

    /**
     * Verify the user's first authenticator-app code and flip 2FA on.
     */
    public function confirm(User $user, string $code): bool
    {
        $secret = $this->cipher->reveal($user->getTotpSecret());
        if (null === $secret || '' === $secret || '' === $code) {
            return false;
        }
        $totp = self::makeTotp($secret, $user);
        if (!$totp->verify($code, null, 10)) {
            return false;
        }

        $user->confirmTotpEnrolment();
        $this->em->flush();

        return true;
    }

    /**
     * Drop the user's 2FA state. Caller must have already verified
     * possession (TOTP code or backup code) — this method does not
     * re-check, it just zeroes the columns.
     */
    public function disable(User $user): void
    {
        $user->disableTotp();
        $this->em->flush();
    }

    /**
     * RBAC-P5-013 (#703) — rotate the recovery-code list while keeping
     * the TOTP secret + enrolment status intact. Used by the
     * "Generate new codes" button in Profile → Security; invalidates
     * every existing code so a lost code list can't be re-used.
     *
     * Returns the cleartext codes ONCE — same one-shot contract as
     * {@see enrol()}. Caller is responsible for surfacing them to the
     * user.
     *
     * @return list<string>
     */
    public function rotateBackupCodes(User $user): array
    {
        [$cleartextCodes, $hashedCodes] = $this->generateBackupCodes();
        // `startTotpEnrolment` wipes `totpEnabledAt`, so we re-confirm
        // immediately after — this keeps the user enrolled while
        // replacing the recovery codes in one round trip.
        $secret = $user->getTotpSecret();
        \assert(null !== $secret && '' !== $secret, 'rotateBackupCodes requires an active enrolment');
        $wasEnabled = $user->isTotpEnabled();
        $previousEnabledAt = $user->getTotpEnabledAt();
        // The stored value is re-used verbatim — already protected (or legacy
        // plaintext), so rotating codes must not double-encrypt it.
        $user->startTotpEnrolment($secret, $hashedCodes);
        if ($wasEnabled) {
            $user->confirmTotpEnrolment($previousEnabledAt);
        }
        $this->em->flush();

        return $cleartextCodes;
    }

    /**
     * Verify a code against an active TOTP user. Falls back to the
     * one-shot backup code list when the TOTP code does not match;
     * a successful backup code is consumed.
     */
    public function verify(User $user, string $code): bool
    {
        $secret = $this->cipher->reveal($user->getTotpSecret());
        if (!$user->isTotpEnabled() || null === $secret || '' === $secret || '' === $code) {
            return false;
        }

        $totp = self::makeTotp($secret, $user);
        if ($totp->verify($code, null, 10)) {
            return true;
        }

        foreach ($user->getTotpBackupCodes() as $hash) {
            if (password_verify($code, $hash)) {
                $user->consumeBackupCode($hash);
                $this->em->flush();

                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    private function generateBackupCodes(): array
    {
        $cleartext = [];
        $hashed = [];
        for ($i = 0; $i < self::BACKUP_CODE_COUNT; ++$i) {
            $code = self::randomBackupCode();
            $cleartext[] = $code;
            // PHP-native password_hash with Argon2id — same algorithm
            // family the Symfony password hasher uses, but without
            // having to thread a UserPasswordHasher through this
            // service. Matches Sprint-0 conventions in production.
            $hashed[] = password_hash($code, PASSWORD_ARGON2ID);
        }

        return [$cleartext, $hashed];
    }

    /**
     * @param non-empty-string $secret
     */
    private static function makeTotp(string $secret, User $user): TOTPInterface
    {
        $email = $user->getEmail();
        \assert('' !== $email, 'User email is enforced NOT NULL by the schema.');

        return TOTP::createFromSecret($secret)
            ->withLabel($email)
            ->withIssuer(self::ISSUER);
    }

    /**
     * @return non-empty-string
     */
    private static function generateSecret(): string
    {
        // 20 random bytes → 160-bit shared secret, the RFC 4226 ceiling
        // and what Google Authenticator / 1Password reach for. base32
        // encoding strips padding because authenticator apps reject it.
        $secret = rtrim(Base32::encodeUpper(random_bytes(20)), '=');
        \assert('' !== $secret);

        return $secret;
    }

    private static function randomBackupCode(): string
    {
        // 10 hex chars (40 bits of entropy) — long enough to brute-force
        // resist the per-user budget, short enough for a sticky note.
        return strtolower(bin2hex(random_bytes(5)));
    }
}
