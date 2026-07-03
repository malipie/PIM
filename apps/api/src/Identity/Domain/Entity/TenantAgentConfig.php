<?php

declare(strict_types=1);

namespace App\Identity\Domain\Entity;

use App\Shared\Application\TenantScoped;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Per-tenant Anthropic BYOK row (#107 / 0.11.12).
 *
 * One config per tenant — the runtime resolver returns the platform
 * key when no row is present (or `disabledAt !== null`).
 *
 * The plaintext key never lives on this object — only the base64
 * ciphertext + the master key version + a 6-character display
 * prefix (`sk-ant-…`) the admin sees in the UI.
 */
class TenantAgentConfig implements TenantScoped
{
    private Uuid $id;

    private ?Tenant $tenant = null;

    /**
     * Base64 of `nonce ‖ ciphertext ‖ tag` from libsodium AEAD output.
     * Opaque to callers — encryption service decodes.
     */
    #[Assert\NotBlank]
    private string $anthropicApiKeyEncrypted;

    /**
     * Master-key version used to produce the ciphertext (per ADR-0017).
     * Resolver lazy re-encrypts to the active version on read.
     */
    #[Assert\Positive]
    private int $encryptionKeyVersion;

    /**
     * First ~6 chars of the plaintext key — `sk-ant-`. Safe to log
     * and to render in the admin UI; useless to an attacker.
     */
    #[Assert\NotBlank]
    #[Assert\Length(max: 16)]
    private string $keyPrefix;

    private DateTimeImmutable $enabledAt;

    private ?DateTimeImmutable $disabledAt = null;

    private ?DateTimeImmutable $lastUsedAt = null;

    /**
     * AGENT-P8-01 (#1983) — opt-in for the proactive data-steward scan
     * (anomalies/gaps reported without a prompt). Off by default: a
     * tenant must explicitly choose proactivity.
     */
    private bool $proactiveScanEnabled = false;

    private DateTimeImmutable $createdAt;

    private DateTimeImmutable $updatedAt;

    public function __construct(
        string $anthropicApiKeyEncrypted,
        int $encryptionKeyVersion,
        string $keyPrefix,
        ?Uuid $id = null,
        ?DateTimeImmutable $enabledAt = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->anthropicApiKeyEncrypted = $anthropicApiKeyEncrypted;
        $this->encryptionKeyVersion = $encryptionKeyVersion;
        $this->keyPrefix = $keyPrefix;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->enabledAt = $enabledAt ?? $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    /**
     * @internal stamped by TenantAssignmentListener on prePersist
     */
    public function assignTenant(Tenant $tenant): void
    {
        if (null !== $this->tenant) {
            throw new LogicException('Tenant is already assigned and cannot be reassigned.');
        }

        $this->tenant = $tenant;
    }

    public function getAnthropicApiKeyEncrypted(): string
    {
        return $this->anthropicApiKeyEncrypted;
    }

    public function getEncryptionKeyVersion(): int
    {
        return $this->encryptionKeyVersion;
    }

    public function getKeyPrefix(): string
    {
        return $this->keyPrefix;
    }

    /**
     * Replace the stored ciphertext (lazy rotation, key change).
     * Bumps `updatedAt` and re-enables the row if it was disabled.
     */
    public function rotateKey(string $newCiphertext, int $newVersion, string $newPrefix): void
    {
        $this->anthropicApiKeyEncrypted = $newCiphertext;
        $this->encryptionKeyVersion = $newVersion;
        $this->keyPrefix = $newPrefix;
        $this->disabledAt = null;
        $this->touch();
    }

    /**
     * Lazy re-encrypt to a newer master-key version without changing
     * the underlying secret. Used on read when the encryption service
     * flags `needsRotation()` true.
     */
    public function reencrypt(string $newCiphertext, int $newVersion): void
    {
        $this->anthropicApiKeyEncrypted = $newCiphertext;
        $this->encryptionKeyVersion = $newVersion;
        $this->touch();
    }

    public function disable(DateTimeImmutable $when): void
    {
        if (null === $this->disabledAt) {
            $this->disabledAt = $when;
            $this->touch();
        }
    }

    public function isEnabled(): bool
    {
        return null === $this->disabledAt;
    }

    public function getEnabledAt(): DateTimeImmutable
    {
        return $this->enabledAt;
    }

    public function getDisabledAt(): ?DateTimeImmutable
    {
        return $this->disabledAt;
    }

    public function isProactiveScanEnabled(): bool
    {
        return $this->proactiveScanEnabled;
    }

    public function setProactiveScanEnabled(bool $enabled): void
    {
        $this->proactiveScanEnabled = $enabled;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getLastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function markUsed(DateTimeImmutable $when): void
    {
        $this->lastUsedAt = $when;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
