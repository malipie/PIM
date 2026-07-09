<?php

declare(strict_types=1);

namespace App\Export\Catalog\Domain\Entity;

use App\Export\Catalog\Domain\Enum\CatalogStatus;
use App\Export\Catalog\Domain\Enum\CatalogTemplateKind;
use App\Shared\Application\TenantScoped;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\Uid\Uuid;

/**
 * Definition of a printable PDF product catalog (ADR-0027 §6.3, CPDF-P1-01) —
 * the catalog-world analogue of {@see \App\Export\Feed\Domain\Entity\FeedProfile}.
 * A catalog is a read-only projection of the assortment; it never mutates
 * `objects` / `object_values`.
 *
 * Complex configuration lives in JSONB columns (the FeedProfile pattern):
 *   - `branding`: logo, palette, company data, cover;
 *   - `field_mappings`: list of {slot, source, transform?} mapping layout slots
 *     to attributes;
 *   - `filter`: filter-DSL snapshot selecting the assortment (nullable).
 *
 * Tenant-scoped with Postgres RLS (defence in depth on top of the Doctrine
 * TenantFilter). UNIQUE(tenant_id, code) enforced at the DB level.
 */
class CatalogProfile extends AggregateRoot implements TenantScoped
{
    private Uuid $id;

    private ?Tenant $tenant = null;

    private string $code;

    private string $name;

    private string $templateKind;

    /** Bare uuid → Catalog\ObjectType (cross-BC decoupling, ADR-0015); MVP always the Product type. */
    private Uuid $objectTypeId;

    /** @var array<mixed, mixed> */
    private array $branding;

    /** @var list<array<mixed, mixed>> */
    private array $fieldMappings;

    /** @var array<mixed, mixed>|null */
    private ?array $filter = null;

    /** Bare uuid → Channel; scopes values per channel (?channel=). */
    private ?Uuid $channelId = null;

    private ?string $publicationChannel = null;

    private ?string $locale = null;

    /** Renderer selection: auto | dompdf | gotenberg (kept as string, not an enum). */
    private string $renderer;

    /** Hash of the URL token (Argon2id, ApiKey pattern); minted at publication config. */
    private ?string $tokenHash = null;

    private ?string $cachedFilePath = null;

    private ?int $cachedFileSize = null;

    private ?int $cachedPageCount = null;

    private ?DateTimeImmutable $cachedAt = null;

    private string $status;

    private DateTimeImmutable $createdAt;

    private DateTimeImmutable $updatedAt;

    /**
     * @param array<mixed, mixed>       $branding
     * @param list<array<mixed, mixed>> $fieldMappings
     * @param array<mixed, mixed>|null  $filter
     */
    public function __construct(
        string $code,
        string $name,
        CatalogTemplateKind $templateKind,
        Uuid $objectTypeId,
        array $branding = [],
        array $fieldMappings = [],
        ?array $filter = null,
        ?Uuid $channelId = null,
        ?string $publicationChannel = null,
        ?string $locale = null,
        string $renderer = 'auto',
        CatalogStatus $status = CatalogStatus::Active,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->code = $code;
        $this->name = $name;
        $this->templateKind = $templateKind->value;
        $this->objectTypeId = $objectTypeId;
        $this->branding = $branding;
        $this->fieldMappings = $fieldMappings;
        $this->filter = $filter;
        $this->channelId = $channelId;
        $this->publicationChannel = $publicationChannel;
        $this->locale = $locale;
        $this->renderer = $renderer;
        $this->status = $status->value;
        $now = new DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function assignTenant(Tenant $tenant): void
    {
        if (null !== $this->tenant) {
            throw new LogicException('Tenant is already assigned and cannot be reassigned.');
        }
        $this->tenant = $tenant;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function getTemplateKind(): CatalogTemplateKind
    {
        return CatalogTemplateKind::from($this->templateKind);
    }

    public function changeTemplateKind(CatalogTemplateKind $templateKind): void
    {
        $this->templateKind = $templateKind->value;
        $this->touch();
    }

    public function getObjectTypeId(): Uuid
    {
        return $this->objectTypeId;
    }

    /** @return array<mixed, mixed> */
    public function getBranding(): array
    {
        return $this->branding;
    }

    /** @param array<mixed, mixed> $branding */
    public function updateBranding(array $branding): void
    {
        $this->branding = $branding;
        $this->touch();
    }

    /** @return list<array<mixed, mixed>> */
    public function getFieldMappings(): array
    {
        return $this->fieldMappings;
    }

    /** @param list<array<mixed, mixed>> $fieldMappings */
    public function updateFieldMappings(array $fieldMappings): void
    {
        $this->fieldMappings = $fieldMappings;
        $this->touch();
    }

    /** @return array<mixed, mixed>|null */
    public function getFilter(): ?array
    {
        return $this->filter;
    }

    /** @param array<mixed, mixed>|null $filter */
    public function updateFilter(?array $filter): void
    {
        $this->filter = $filter;
        $this->touch();
    }

    public function getChannelId(): ?Uuid
    {
        return $this->channelId;
    }

    public function getPublicationChannel(): ?string
    {
        return $this->publicationChannel;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function setScope(
        ?Uuid $channelId,
        ?string $publicationChannel,
        ?string $locale,
    ): void {
        $this->channelId = $channelId;
        $this->publicationChannel = $publicationChannel;
        $this->locale = $locale;
        $this->touch();
    }

    public function getRenderer(): string
    {
        return $this->renderer;
    }

    public function setRenderer(string $renderer): void
    {
        $this->renderer = $renderer;
        $this->touch();
    }

    public function getTokenHash(): ?string
    {
        return $this->tokenHash;
    }

    public function setTokenHash(?string $tokenHash): void
    {
        $this->tokenHash = $tokenHash;
        $this->touch();
    }

    public function getStatus(): CatalogStatus
    {
        return CatalogStatus::from($this->status);
    }

    public function pause(): void
    {
        $this->status = CatalogStatus::Paused->value;
        $this->touch();
    }

    public function resume(): void
    {
        $this->status = CatalogStatus::Active->value;
        $this->touch();
    }

    public function markError(): void
    {
        $this->status = CatalogStatus::Error->value;
        $this->touch();
    }

    public function getCachedFilePath(): ?string
    {
        return $this->cachedFilePath;
    }

    public function getCachedFileSize(): ?int
    {
        return $this->cachedFileSize;
    }

    public function getCachedPageCount(): ?int
    {
        return $this->cachedPageCount;
    }

    public function getCachedAt(): ?DateTimeImmutable
    {
        return $this->cachedAt;
    }

    /**
     * Record the result of a successful regeneration: the cached file becomes
     * the one the public URL serves.
     */
    public function recordCache(string $filePath, int $fileSize, int $pageCount, DateTimeImmutable $at): void
    {
        $this->cachedFilePath = $filePath;
        $this->cachedFileSize = $fileSize;
        $this->cachedPageCount = $pageCount;
        $this->cachedAt = $at;
        if (CatalogStatus::Error === $this->getStatus()) {
            $this->status = CatalogStatus::Active->value;
        }
        $this->touch();
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
