<?php

declare(strict_types=1);

namespace App\Identity\Application\Sso;

use App\Shared\Application\Crypto\SecretCipher;

/**
 * #2725 — encrypts the secret-shaped leaves of an {@see \App\Identity\Domain\Entity\SsoProvider}
 * config at rest.
 *
 * The config is one JSONB blob mixing public settings (client id, hosted
 * domain, entity id) with credentials that grant access to the tenant's IdP:
 * `client_secret`, `private_key`, `idp_certificate`, `sp_private_key`. The
 * entity's own docblock said secrets "MUST be encrypted at the application
 * layer" while the controller stored them verbatim, so a database dump handed
 * over the tenant's OAuth/SAML credentials.
 *
 * Only the secret leaves are wrapped, so the non-secret settings stay readable
 * in the database (queryable, greppable, diffable in audit logs). The marker
 * list is shared with the controller's masked-secret merge, so "what counts as
 * a secret" is defined once.
 *
 * Values already carrying the {@see SecretCipher} envelope are left alone, and
 * legacy plaintext reveals as itself — a provider written before #2725 keeps
 * authenticating and migrates on its next save.
 */
final readonly class SsoConfigCipher
{
    /**
     * Config keys (or key fragments) whose values are credentials.
     *
     * @var list<string>
     */
    public const array SECRET_MARKERS = ['client_secret', 'private_key', 'idp_certificate', 'sp_private_key'];

    public function __construct(private SecretCipher $cipher)
    {
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public function protect(array $config): array
    {
        foreach ($config as $key => $value) {
            if (!\is_string($value) || !self::isSecretKey($key) || $this->cipher->isProtected($value)) {
                continue;
            }
            $config[$key] = $this->cipher->protect($value);
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public function reveal(array $config): array
    {
        foreach ($config as $key => $value) {
            if (!\is_string($value) || !self::isSecretKey($key)) {
                continue;
            }
            $config[$key] = $this->cipher->reveal($value);
        }

        return $config;
    }

    public static function isSecretKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::SECRET_MARKERS as $marker) {
            if ($lower === $marker || str_contains($lower, $marker)) {
                return true;
            }
        }

        return false;
    }
}
