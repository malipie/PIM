<?php

declare(strict_types=1);

namespace App\Export\Feed\Application\Delivery;

use App\Shared\Application\Crypto\EncryptedSecret;
use App\Shared\Application\Crypto\EncryptionServiceInterface;
use InvalidArgumentException;

/**
 * Builds / reads a feed's delivery config JSONB (ADR-0023 §6.9, XMLF-P3-04):
 * gzip toggle + optional HTTP Basic auth. The Basic password is encrypted with
 * the shared AES-256-GCM service (reversible — it must be compared with the
 * incoming credential), never stored or returned in plaintext.
 */
final class FeedDeliveryConfig
{
    public function __construct(private readonly EncryptionServiceInterface $encryption)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(bool $gzip, string $authType, ?string $username = null, ?string $password = null): array
    {
        if ('basic' === $authType) {
            if (null === $username || '' === $username || null === $password || '' === $password) {
                throw new InvalidArgumentException('HTTP Basic auth requires a username and password.');
            }
            $secret = $this->encryption->encrypt($password);

            return [
                'gzip' => $gzip,
                'auth' => [
                    'type' => 'basic',
                    'username' => $username,
                    'encrypted_password' => $secret->ciphertext,
                    'encryption_version' => $secret->version,
                ],
            ];
        }

        return ['gzip' => $gzip, 'auth' => ['type' => 'none']];
    }

    public function gzipEnabled(mixed $delivery): bool
    {
        return \is_array($delivery) && true === ($delivery['gzip'] ?? false);
    }

    /**
     * Decrypt the stored Basic credentials for comparison at serve time
     * (XMLF-P3-05). Returns null when the feed is not Basic-protected.
     *
     * @return array{username: string, password: string}|null
     */
    public function basicCredentials(mixed $delivery): ?array
    {
        if (!\is_array($delivery) || !\is_array($delivery['auth'] ?? null)) {
            return null;
        }
        $auth = $delivery['auth'];
        if ('basic' !== ($auth['type'] ?? null)) {
            return null;
        }
        $username = \is_string($auth['username'] ?? null) ? $auth['username'] : '';
        $ciphertext = \is_string($auth['encrypted_password'] ?? null) ? $auth['encrypted_password'] : '';
        $version = \is_int($auth['encryption_version'] ?? null) ? $auth['encryption_version'] : 0;
        if ('' === $ciphertext) {
            return null;
        }

        return ['username' => $username, 'password' => $this->encryption->decrypt(new EncryptedSecret($ciphertext, $version))];
    }
}
