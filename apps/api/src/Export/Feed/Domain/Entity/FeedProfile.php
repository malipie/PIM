<?php

declare(strict_types=1);

namespace App\Export\Feed\Domain\Entity;

use App\Export\Feed\Domain\Enum\FeedStatus;
use App\Export\Feed\Domain\Enum\FeedTemplateKind;
use App\Export\Feed\Domain\Enum\FeedValidationPolicy;
use App\Shared\Application\TenantScoped;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\Uid\Uuid;

/**
 * Definition of an outgoing product XML feed (ADR-0023 §6.2, XMLF-P1-01) — the
 * feed-world analogue of {@see \App\Export\Domain\Entity\ExportProfile} plus a
 * stable URL token, schedule and cache metadata. A feed is a read-only
 * projection of the catalog; it never mutates `objects` / `object_values`.
 *
 * Complex configuration lives in JSONB columns (the ExportProfile pattern):
 *   - `descriptor`: declarative XML structure (root/namespaces/slots) —
 *     validated by the FeedDescriptor VO (XMLF-P2-01);
 *   - `field_mappings`: list of {slot, source, transform?} (XMLF-P2-03);
 *   - `filter`: filter-DSL snapshot selecting the assortment (XMLF-P3-02);
 *   - `delivery`: {gzip, auth:{type,username?,encrypted_password?}} (XMLF-P3-04).
 *
 * Tenant-scoped with Postgres RLS (defence in depth on top of the Doctrine
 * TenantFilter). UNIQUE(tenant_id, code) enforced at the DB level.
 */
class FeedProfile extends AggregateRoot implements TenantScoped
{
    private Uuid $id;

    private ?Tenant $tenant = null;

    private string $code;

    private string $name;

    private string $templateKind;

    /** Bare uuid → Catalog\ObjectType (cross-BC decoupling, ADR-0015); MVP always the Product type. */
    private Uuid $objectTypeId;

    /** @var array<string, mixed> */
    private array $descriptor;

    /** @var list<array<string, mixed>> */
    private array $fieldMappings;

    /** @var array<string, mixed>|null */
    private ?array $filter = null;

    /** Bare uuid → Channel; scopes values per channel (?channel=). */
    private ?Uuid $channelId = null;

    private ?string $publicationChannel = null;

    private ?string $locale = null;

    private ?string $currency = null;

    private ?string $scheduleCron = null;

    /** @var array<string, mixed> */
    private array $delivery;

    /** Hash of the URL token (Argon2id, ApiKey pattern); minted at delivery config (XMLF-P3-04). */
    private ?string $tokenHash = null;

    private string $status;

    private string $validationPolicy;

    private ?Uuid $lastRunId = null;

    private ?string $cachedFilePath = null;

    private ?int $cachedFileSize = null;

    private ?int $cachedItemCount = null;

    private ?DateTimeImmutable $cachedAt = null;

    private DateTimeImmutable $createdAt;

    private DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed>       $descriptor
     * @param list<array<string, mixed>> $fieldMappings
     * @param array<string, mixed>|null  $filter
     * @param array<string, mixed>       $delivery
     */
    public function __construct(
        string $code,
        string $name,
        FeedTemplateKind $templateKind,
        Uuid $objectTypeId,
        array $descriptor,
        array $fieldMappings = [],
        ?array $filter = null,
        ?Uuid $channelId = null,
        ?string $publicationChannel = null,
        ?string $locale = null,
        ?string $currency = null,
        ?string $scheduleCron = null,
        array $delivery = [],
        FeedValidationPolicy $validationPolicy = FeedValidationPolicy::SkipInvalid,
        FeedStatus $status = FeedStatus::Active,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->code = $code;
        $this->name = $name;
        $this->templateKind = $templateKind->value;
        $this->objectTypeId = $objectTypeId;
        $this->descriptor = $descriptor;
        $this->fieldMappings = $fieldMappings;
        $this->filter = $filter;
        $this->channelId = $channelId;
        $this->publicationChannel = $publicationChannel;
        $this->locale = $locale;
        $this->currency = $currency;
        $this->scheduleCron = $scheduleCron;
        $this->delivery = $delivery;
        $this->validationPolicy = $validationPolicy->value;
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

    public function getTemplateKind(): FeedTemplateKind
    {
        return FeedTemplateKind::from($this->templateKind);
    }

    public function getObjectTypeId(): Uuid
    {
        return $this->objectTypeId;
    }

    /** @return array<string, mixed> */
    public function getDescriptor(): array
    {
        return $this->descriptor;
    }

    /** @param array<string, mixed> $descriptor */
    public function updateDescriptor(array $descriptor): void
    {
        $this->descriptor = $descriptor;
        $this->touch();
    }

    /** @return list<array<string, mixed>> */
    public function getFieldMappings(): array
    {
        return $this->fieldMappings;
    }

    /** @param list<array<string, mixed>> $fieldMappings */
    public function updateFieldMappings(array $fieldMappings): void
    {
        $this->fieldMappings = $fieldMappings;
        $this->touch();
    }

    /** @return array<string, mixed>|null */
    public function getFilter(): ?array
    {
        return $this->filter;
    }

    /** @param array<string, mixed>|null $filter */
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

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setScope(
        ?Uuid $channelId,
        ?string $publicationChannel,
        ?string $locale,
        ?string $currency,
    ): void {
        $this->channelId = $channelId;
        $this->publicationChannel = $publicationChannel;
        $this->locale = $locale;
        $this->currency = $currency;
        $this->touch();
    }

    public function getScheduleCron(): ?string
    {
        return $this->scheduleCron;
    }

    public function setScheduleCron(?string $scheduleCron): void
    {
        $this->scheduleCron = $scheduleCron;
        $this->touch();
    }

    /** @return array<string, mixed> */
    public function getDelivery(): array
    {
        return $this->delivery;
    }

    /** @param array<string, mixed> $delivery */
    public function updateDelivery(array $delivery): void
    {
        $this->delivery = $delivery;
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

    public function getStatus(): FeedStatus
    {
        return FeedStatus::from($this->status);
    }

    public function pause(): void
    {
        $this->status = FeedStatus::Paused->value;
        $this->touch();
    }

    public function resume(): void
    {
        $this->status = FeedStatus::Active->value;
        $this->touch();
    }

    public function markError(): void
    {
        $this->status = FeedStatus::Error->value;
        $this->touch();
    }

    public function getValidationPolicy(): FeedValidationPolicy
    {
        return FeedValidationPolicy::from($this->validationPolicy);
    }

    public function setValidationPolicy(FeedValidationPolicy $policy): void
    {
        $this->validationPolicy = $policy->value;
        $this->touch();
    }

    public function getLastRunId(): ?Uuid
    {
        return $this->lastRunId;
    }

    public function getCachedFilePath(): ?string
    {
        return $this->cachedFilePath;
    }

    public function getCachedFileSize(): ?int
    {
        return $this->cachedFileSize;
    }

    public function getCachedItemCount(): ?int
    {
        return $this->cachedItemCount;
    }

    public function getCachedAt(): ?DateTimeImmutable
    {
        return $this->cachedAt;
    }

    /**
     * Record the result of a successful regeneration: the cached file becomes
     * the one the public URL serves (XMLF-P3-05).
     */
    public function recordCache(Uuid $runId, string $filePath, int $fileSize, int $itemCount, DateTimeImmutable $at): void
    {
        $this->lastRunId = $runId;
        $this->cachedFilePath = $filePath;
        $this->cachedFileSize = $fileSize;
        $this->cachedItemCount = $itemCount;
        $this->cachedAt = $at;
        if (FeedStatus::Error === $this->getStatus()) {
            $this->status = FeedStatus::Active->value;
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
