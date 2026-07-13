<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Entity;

use App\Shared\Application\SystemShipped;
use App\Shared\Application\TenantScoped;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\Uid\Uuid;

/**
 * VIEW-09 (#535) — rule-based smart filter preset surfaced in the
 * products list smart filter row.
 *
 * Two ownership axes:
 *   - `(tenant_id IS NULL, user_id IS NULL, is_built_in=true)` — system
 *     shipped, immutable, visible to every tenant.
 *   - `(tenant_id=current, user_id=current)` — user-defined, owner can
 *     PATCH/DELETE.
 *   - `(tenant_id=current, user_id IS NULL)` — tenant-shared lane,
 *     reserved for Faza 1+ "share to team" UX (schema supports it now).
 *
 * The `query` JSONB is a flat filter DSL in MVP (VIEW-09 / VIEW-10);
 * nested AND/OR/NOT lands in VIEW-09b without schema change.
 *
 * Marketing nota PRD §11 — "smart" tutaj znaczy *rule-based*, nie LLM.
 * Nie używaj "AI-powered" / "AI smart filter" w copy ani docstringach.
 */
class SmartFilterPreset implements TenantScoped, SystemShipped
{
    private Uuid $id;
    private ?Tenant $tenant = null;
    private ?Uuid $userId;
    private string $slug;

    /** @var array{pl: string, en: string} */
    private array $name;

    private string $icon;

    /** @var array<string, mixed> */
    private array $query;

    /**
     * PTR-01 — column overrides snapshot merged into the preset (the old
     * "saved view" role). Each entry mirrors the frontend GridColumnOverride:
     * {key: string, hidden?: bool, width?: int, position?: int}. NULL = the
     * preset carries no column preferences (filter-only, legacy shape).
     *
     * @var list<array<string, mixed>>|null
     */
    private ?array $columns = null;

    private bool $isBuiltIn;
    private int $sortOrder;
    /**
     * UP-05 (#1020) — scopes the preset to a specific ObjectType.code
     * (mirrors `saved_views.resource`). NULL = visible across every kind
     * (legacy semantics). Tenant-side presets created from UniversalListPage
     * carry the kind code so they stay segregated.
     */
    private ?string $resource = null;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $updatedAt;

    /**
     * @param array{pl: string, en: string}   $name
     * @param array<string, mixed>            $query
     * @param list<array<string, mixed>>|null $columns
     */
    public function __construct(
        string $slug,
        array $name,
        string $icon,
        array $query,
        ?Uuid $userId = null,
        bool $isBuiltIn = false,
        int $sortOrder = 0,
        ?Uuid $id = null,
        ?string $resource = null,
        ?array $columns = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->slug = $slug;
        $this->name = $name;
        $this->icon = $icon;
        $this->query = $query;
        $this->userId = $userId;
        $this->isBuiltIn = $isBuiltIn;
        $this->sortOrder = $sortOrder;
        $this->resource = $resource;
        $this->columns = $columns;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
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
     * @internal stamped by TenantAssignmentListener on prePersist for
     *           user-defined presets; system-shipped rows silently skip
     *           the assignment so they stay tenant-less
     */
    public function assignTenant(Tenant $tenant): void
    {
        if (null !== $this->tenant) {
            throw new LogicException('Tenant is already assigned and cannot be reassigned.');
        }

        if ($this->isBuiltIn) {
            // Built-in presets are system-shipped (tenant_id IS NULL) by
            // contract — silently ignore listener assignments so the
            // shared TenantAssignmentListener does not need a per-entity
            // exception list.
            return;
        }

        $this->tenant = $tenant;
    }

    public function getUserId(): ?Uuid
    {
        return $this->userId;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function changeSlug(string $slug): void
    {
        $this->guardMutable();
        $this->slug = $slug;
        $this->touch();
    }

    /**
     * @return array{pl: string, en: string}
     */
    public function getName(): array
    {
        return $this->name;
    }

    /**
     * @param array{pl: string, en: string} $name
     */
    public function rename(array $name): void
    {
        $this->guardMutable();
        $this->name = $name;
        $this->touch();
    }

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function changeIcon(string $icon): void
    {
        $this->guardMutable();
        $this->icon = $icon;
        $this->touch();
    }

    /**
     * @return array<string, mixed>
     */
    public function getQuery(): array
    {
        return $this->query;
    }

    /**
     * @param array<string, mixed> $query
     */
    public function updateQuery(array $query): void
    {
        $this->guardMutable();
        $this->query = $query;
        $this->touch();
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function getColumns(): ?array
    {
        return $this->columns;
    }

    /**
     * @param list<array<string, mixed>>|null $columns
     */
    public function updateColumns(?array $columns): void
    {
        $this->guardMutable();
        $this->columns = $columns;
        $this->touch();
    }

    public function isBuiltIn(): bool
    {
        return $this->isBuiltIn;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function reorder(int $sortOrder): void
    {
        $this->guardMutable();
        $this->sortOrder = $sortOrder;
        $this->touch();
    }

    public function isSystem(): bool
    {
        return null === $this->userId && null === $this->tenant;
    }

    public function isTenantShared(): bool
    {
        return null === $this->userId && null !== $this->tenant;
    }

    public function getResource(): ?string
    {
        return $this->resource;
    }

    public function changeResource(?string $resource): void
    {
        $this->guardMutable();
        $this->resource = $resource;
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

    public function isOwnedBy(Uuid $userId): bool
    {
        return null !== $this->userId && $this->userId->equals($userId);
    }

    private function guardMutable(): void
    {
        if ($this->isBuiltIn) {
            throw new LogicException('Built-in smart filter presets are immutable.');
        }
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
