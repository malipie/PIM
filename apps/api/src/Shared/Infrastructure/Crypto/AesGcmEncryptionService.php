<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Crypto;

use App\Shared\Application\Crypto\EncryptedSecret;
use App\Shared\Application\Crypto\EncryptionServiceInterface;
use RuntimeException;

use const SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES;

/**
 * AES-256-GCM at-rest encryption backed by libsodium (#107 / 0.11.12).
 *
 * See ADR-0017 for the algorithm + versioning rationale.
 *
 * Configured with a map of `version => 32-byte master key`. The
 * highest-numbered version is the "active" one used for new writes;
 * older versions stay loaded so existing rows decrypt without a
 * forced sweep.
 */
final readonly class AesGcmEncryptionService implements EncryptionServiceInterface
{
    /**
     * @var array<int, string> map of version → raw 32-byte key
     */
    private array $keysByVersion;

    private int $activeVersion;

    /**
     * @param array<int, string> $rawKeysByVersion map of version → base64-encoded
     *                                             32-byte key (the env var format)
     * @param string             $appEnv           kernel.environment — prod refuses
     *                                             the all-zero placeholder key
     */
    public function __construct(array $rawKeysByVersion, private string $appEnv = 'dev')
    {
        if ([] === $rawKeysByVersion) {
            throw new RuntimeException(
                'BYOK encryption requires at least one master key. '.
                'Set APP_BYOK_KEY_V1=<base64 32-byte> in env.',
            );
        }

        // Constants are not defined at boot when libsodium is missing — use
        // the literal byte length (32 = SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES).
        // The AES-256-GCM availability check moves to encrypt()/decrypt() so
        // a host without AES-NI can still boot and route traffic that does
        // not touch BYOK paths (PHPStan introspection, CI runners, etc.).
        $decoded = [];
        foreach ($rawKeysByVersion as $version => $b64Key) {
            $raw = base64_decode($b64Key, true);
            if (false === $raw || 32 !== \strlen($raw)) {
                throw new RuntimeException(\sprintf(
                    'BYOK master key version %d is not a valid base64 32-byte string.',
                    $version,
                ));
            }
            // The tracked .env ships an all-zero placeholder so dev boots out
            // of the box. That key is public by definition — production must
            // never encrypt SSO/TOTP/BYOK/integration secrets with it.
            if ('prod' === $this->appEnv && $raw === str_repeat("\0", 32)) {
                throw new RuntimeException(\sprintf(
                    'BYOK master key version %d is the all-zero placeholder from the tracked .env. '.
                    'Generate a real key (php -r "echo base64_encode(random_bytes(32));") and set APP_BYOK_KEY_V1.',
                    $version,
                ));
            }
            $decoded[$version] = $raw;
        }
        ksort($decoded);

        $this->keysByVersion = $decoded;
        /** @var int $maxVersion */
        $maxVersion = max(array_keys($decoded));
        $this->activeVersion = $maxVersion;
    }

    private function assertCipherAvailable(): void
    {
        if (\function_exists('sodium_crypto_aead_aes256gcm_is_available')
            && sodium_crypto_aead_aes256gcm_is_available()) {
            return;
        }

        throw new RuntimeException(
            'libsodium AES-256-GCM not available on this host. '.
            'Recompile PHP with --with-sodium or run on AES-NI hardware.',
        );
    }

    public function encrypt(string $plaintext): EncryptedSecret
    {
        $this->assertCipherAvailable();

        $key = $this->keysByVersion[$this->activeVersion];
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES);
        // No additional data (AAD) in MVP — version field is the contract,
        // not a free-form context binding. Add AAD if we ever need to bind
        // ciphertexts to e.g. tenant_id (defence in depth).
        $ciphertext = sodium_crypto_aead_aes256gcm_encrypt(
            $plaintext,
            (string) $this->activeVersion,
            $nonce,
            $key,
        );

        return new EncryptedSecret(
            ciphertext: base64_encode($nonce.$ciphertext),
            version: $this->activeVersion,
        );
    }

    public function decrypt(EncryptedSecret $secret): string
    {
        $this->assertCipherAvailable();

        if (!isset($this->keysByVersion[$secret->version])) {
            throw new RuntimeException(\sprintf(
                'BYOK master key version %d is not loaded — operator must keep '.
                'older versions in env until ciphertexts are swept.',
                $secret->version,
            ));
        }

        $blob = base64_decode($secret->ciphertext, true);
        if (false === $blob || \strlen($blob) <= SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES) {
            throw new RuntimeException('BYOK ciphertext is malformed.');
        }

        $nonce = substr($blob, 0, SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES);
        $ciphertext = substr($blob, SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES);

        $plaintext = sodium_crypto_aead_aes256gcm_decrypt(
            $ciphertext,
            (string) $secret->version,
            $nonce,
            $this->keysByVersion[$secret->version],
        );
        if (false === $plaintext) {
            // GCM auth tag mismatch — ciphertext was tampered with or the
            // wrong key was used. Either way: do not surface what failed.
            throw new RuntimeException('BYOK ciphertext failed integrity check.');
        }

        return $plaintext;
    }

    public function needsRotation(EncryptedSecret $secret): bool
    {
        return $secret->version < $this->activeVersion;
    }
}
