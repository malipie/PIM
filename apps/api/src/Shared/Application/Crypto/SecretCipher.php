<?php

declare(strict_types=1);

namespace App\Shared\Application\Crypto;

/**
 * String-in / string-out wrapper over {@see EncryptionServiceInterface} for
 * secrets that live in a SINGLE existing column (#2725, #2726).
 *
 * {@see \App\Identity\Application\ByokKeyManager} stores the ciphertext and the
 * key version in two dedicated columns, which is the better shape when the
 * schema is designed for it. The TOTP secret and the SSO provider config
 * predate that: they occupy one plain column (and, for SSO, one JSONB leaf), so
 * this helper packs the version INTO the value as `enc:v{N}:{base64}`.
 *
 * `reveal()` passes any value lacking that prefix through untouched, so rows
 * written before encryption keep working and migrate lazily on their next
 * write. That also means an operator can tell at a glance whether a row is
 * still plaintext — `SELECT ... WHERE col NOT LIKE 'enc:v%'`.
 */
final readonly class SecretCipher
{
    private const string PREFIX = 'enc:v';

    public function __construct(private EncryptionServiceInterface $encryption)
    {
    }

    /**
     * Encrypts a plaintext secret into an opaque single-column token. Empty
     * strings are returned as-is: they carry no secret and encrypting them
     * would only make "is this set?" checks lie.
     */
    public function protect(string $plaintext): string
    {
        if ('' === $plaintext) {
            return $plaintext;
        }

        $secret = $this->encryption->encrypt($plaintext);

        return self::PREFIX.$secret->version.':'.$secret->ciphertext;
    }

    /**
     * Decrypts a token produced by {@see protect()}. A value without the
     * envelope prefix is legacy plaintext and is returned unchanged.
     */
    public function reveal(?string $stored): ?string
    {
        if (null === $stored || !str_starts_with($stored, self::PREFIX)) {
            return $stored;
        }

        $rest = substr($stored, \strlen(self::PREFIX));
        $separator = strpos($rest, ':');
        if (false === $separator) {
            return $stored;
        }

        $version = (int) substr($rest, 0, $separator);
        $ciphertext = substr($rest, $separator + 1);

        return $this->encryption->decrypt(new EncryptedSecret($ciphertext, $version));
    }

    /** Whether the stored value is already encrypted (i.e. carries the envelope). */
    public function isProtected(?string $stored): bool
    {
        return null !== $stored && str_starts_with($stored, self::PREFIX);
    }
}
