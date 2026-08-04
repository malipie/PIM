<?php

declare(strict_types=1);

namespace App\Identity\Domain\Entity;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * SSO provider config per tenant (Google Workspace / Microsoft 365 / SAML).
 *
 * Schema landed in #770 (RBAC-P1-004). Entity deferred from P1-008 until
 * the SSO substrate ticket (this PR). Phase 2 #661 (Google) / #662
 * (Microsoft) / #663 (SAML) wire the actual OAuth/SAML flows; this entity
 * stores the per-tenant config (client_id, secret, etc. — secret values
 * encrypted via ByokKeyManager pattern in the JSONB config field).
 *
 * Kind discriminator: 'google_workspace' | 'microsoft_365' | 'saml'.
 */
class SsoProvider
{
    public const string KIND_GOOGLE_WORKSPACE = 'google_workspace';
    public const string KIND_MICROSOFT_365 = 'microsoft_365';
    public const string KIND_SAML = 'saml';

    private Uuid $id;

    private Uuid $tenantId;

    private string $kind;

    private string $name;

    /**
     * Provider-specific config — client_id, encrypted client_secret,
     * hosted_domain (Google), tenant_id (Microsoft), idp_metadata_xml
     * (SAML), etc. Secrets ARE encrypted at the application layer (#2725, SsoConfigCipher)
     * via ByokKeyManager before persistence.
     *
     * @var array<string, mixed>
     */
    private array $config;

    private bool $enabled;

    private DateTimeImmutable $createdAt;

    private ?DateTimeImmutable $updatedAt = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        Uuid $tenantId,
        string $kind,
        string $name,
        array $config = [],
        bool $enabled = false,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->tenantId = $tenantId;
        $this->kind = $kind;
        $this->name = $name;
        $this->config = $config;
        $this->enabled = $enabled;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenantId(): Uuid
    {
        return $this->tenantId;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function updateConfig(array $config): void
    {
        $this->config = $config;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function enable(): void
    {
        $this->enabled = true;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function disable(): void
    {
        $this->enabled = false;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function rename(string $name): void
    {
        $this->name = $name;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
