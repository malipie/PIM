<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Contracts\Byok\ByokKeyResolverInterface;
use App\Identity\Domain\Entity\TenantAgentConfig;
use App\Identity\Domain\Repository\TenantAgentConfigRepositoryInterface;
use App\Shared\Application\Crypto\EncryptedSecret;
use App\Shared\Application\Crypto\EncryptionServiceInterface;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;

/**
 * Coordinates BYOK key lifecycle for a tenant (#107 / 0.11.12):
 *
 * - **set** — encrypt + persist (creates row or rotates existing key).
 * - **disable** — soft-disable without losing the ciphertext (operator
 *   can re-enable later by setting a fresh key).
 * - **resolveKey** — read path used by the agent client factory:
 *   decrypts, lazy re-encrypts to the active master version when
 *   `EncryptionService::needsRotation()` returns true, bumps
 *   `last_used_at`. Returns `null` when no row / disabled, so the
 *   caller can fall through to the platform key.
 *
 * The plaintext key never reaches a callee outside this service except
 * through `resolveKey()` — and even there it's a transient string the
 * caller is expected to hand straight to the Anthropic SDK.
 */
final readonly class ByokKeyManager implements ByokKeyResolverInterface
{
    private const int PREFIX_LENGTH = 8;

    public function __construct(
        private TenantAgentConfigRepositoryInterface $configs,
        private EncryptionServiceInterface $encryption,
    ) {
    }

    public function setKey(Tenant $tenant, string $plaintextApiKey): TenantAgentConfig
    {
        $secret = $this->encryption->encrypt($plaintextApiKey);
        $prefix = substr($plaintextApiKey, 0, self::PREFIX_LENGTH);

        $existing = $this->configs->findForTenant($tenant);
        if (null === $existing) {
            $config = new TenantAgentConfig(
                anthropicApiKeyEncrypted: $secret->ciphertext,
                encryptionKeyVersion: $secret->version,
                keyPrefix: $prefix,
            );
            $this->configs->save($config);

            return $config;
        }

        $existing->rotateKey($secret->ciphertext, $secret->version, $prefix);
        $this->configs->save($existing);

        return $existing;
    }

    public function disable(Tenant $tenant): void
    {
        $config = $this->configs->findForTenant($tenant);
        if (null === $config) {
            return;
        }

        $config->disable(new DateTimeImmutable());
        $this->configs->save($config);
    }

    /**
     * Plaintext API key for the tenant, or `null` if BYOK is not
     * configured / disabled. Caller MUST pass the result to the LLM
     * client immediately — do not store, log, or echo.
     */
    public function resolveKey(Tenant $tenant): ?string
    {
        $config = $this->configs->findForTenant($tenant);
        if (null === $config || !$config->isEnabled()) {
            return null;
        }

        $secret = new EncryptedSecret(
            ciphertext: $config->getAnthropicApiKeyEncrypted(),
            version: $config->getEncryptionKeyVersion(),
        );
        $plaintext = $this->encryption->decrypt($secret);

        if ($this->encryption->needsRotation($secret)) {
            $rotated = $this->encryption->encrypt($plaintext);
            $config->reencrypt($rotated->ciphertext, $rotated->version);
        }

        $config->markUsed(new DateTimeImmutable());
        $this->configs->save($config);

        return $plaintext;
    }

    public function hasActiveKey(Tenant $tenant): bool
    {
        $config = $this->configs->findForTenant($tenant);

        return null !== $config && $config->isEnabled();
    }
}
