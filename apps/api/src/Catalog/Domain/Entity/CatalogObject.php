<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Entity;

use App\Catalog\Contracts\Event\ObjectArchived;
use App\Catalog\Contracts\Event\ObjectAttributesChanged;
use App\Catalog\Contracts\Event\ObjectCategoriesChanged;
use App\Catalog\Contracts\Event\ObjectCreated;
use App\Catalog\Contracts\Event\ObjectEnabledChanged;
use App\Catalog\Contracts\Event\ObjectPublished;
use App\Catalog\Domain\ObjectKind;
use App\Shared\Application\Blameable;
use App\Shared\Application\TenantScoped;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

use const ARRAY_FILTER_USE_BOTH;

/**
 * Generic catalog object — polymorphic per `kind` (per ADR-009).
 *
 * One table for every domain entity in PIM: products, categories, assets,
 * and (phase 2/3) custom kinds. Sugar API paths in #41 expose the same
 * row as `/api/products`, `/api/categories`, `/api/assets` for DX.
 *
 * Class name is `CatalogObject` (not `Object`) because `Object` is
 * effectively a reserved keyword in PHP (cannot use it as a class name
 * since 7.2). The Doctrine table is still `objects`.
 *
 * `kind` is denormalised from `object_type_id → object_types.kind` to
 * cheapen WHERE clauses on the hot read path. A Doctrine listener (in
 * #33 / #38) keeps the two in sync; admins never set `kind` directly.
 *
 * `attributes_indexed JSONB` is the denormalised cache of every
 * ObjectValue for this row, keyed by attribute code (`{name: {pl: '…',
 * en: '…'}, sku: '…', color: 'red'}`). The GIN index lets the search
 * layer (#52) answer `attributes_indexed @> '{"color": "red"}'` in
 * sub-50ms for 10k×200×3 dataset (DoD benchmark of #34).
 *
 * `path LTREE` is nullable — only `kind='category'` rows carry it.
 * The `kind = 'category' OR path IS NULL` invariant + partial indexes
 * land in #33 along with the validator listener; this migration just
 * adds the column so #33 can constrain it without an ALTER.
 *
 * `parent_id` is the self-FK used by:
 *   - `kind='product'` for variants (size/color of a parent SKU);
 *   - `kind='category'` for the tree (parent category in ltree).
 */
class CatalogObject extends AggregateRoot implements TenantScoped, Blameable
{
    public const string STATUS_DRAFT = 'draft';
    public const string STATUS_REVIEW = 'review';
    public const string STATUS_PUBLISHED = 'published';
    public const string STATUS_ARCHIVED = 'archived';
    private Uuid $id;
    private ?Tenant $tenant = null;
    /**
     * #1207 — snapshot of the actor (e-mail) who created / last updated this
     * row, stamped by {@see \App\Shared\Infrastructure\Doctrine\EventListener\BlameableAssignmentListener}.
     * Null for rows written without a security context (CLI seeders, import).
     */
    private ?string $createdBy = null;
    private ?string $updatedBy = null;
    private ObjectType $objectType;
    private ObjectKind $kind;
    #[Assert\NotBlank]
    #[Assert\Length(max: 128)]
    private string $code;
    private ?self $parent = null;

    /**
     * ADR-015 — the categorizable ObjectType whose tree this category
     * belongs to. Only meaningful for `kind='category'` (NULL otherwise);
     * partitions the otherwise-shared ltree namespace so each ObjectType
     * has its own category tree. The category distributes attribute groups
     * to instances of this target ObjectType (the `target_object_type_id`
     * in `category_attribute_groups` equals this scope by construction).
     */
    private ?ObjectType $categoryTargetObjectType = null;

    private bool $enabled = true;

    private string $status = self::STATUS_DRAFT;

    /**
     * @var array<string, mixed>
     */
    private array $completeness = [];

    /**
     * Flat percentage 0..100 mirrored from `completeness['global']` for
     * indexable filter/sort on the products list (UI-02.1, UI-02.10).
     * Maintained by {@see AttributesIndexedRebuilder}.
     */
    private int $completenessPct = 0;

    /**
     * Per-product sync aggregate badge — `green|yellow|red|gray`. Default
     * `gray` (no sync history). Mutated by Faza 1 channel-sync subscriber;
     * MVP only ships the column + serialisation (UI-02.1 / UI-02.5 / UI-02.10).
     */
    private string $syncStatusAggregate = 'gray';

    /**
     * @var array<string, mixed>
     */
    private array $attributesIndexed = [];

    /**
     * Postgres LTREE column (`#33`). Custom Doctrine type
     * {@see \App\Catalog\Infrastructure\Doctrine\Type\LtreeType} maps it
     * as `?string` on the PHP side. Nullable + a CHECK constraint on the
     * database pins "path is for `kind='category'` only"; the
     * {@see \App\Catalog\Infrastructure\Doctrine\EventListener\CategoryPathValidator}
     * enforces the same invariant on writes with a friendlier error
     * message and validates ltree label format.
     */
    private ?string $path = null;

    /**
     * CHC-04 (#1288) — effective attribute-group snapshot captured on first
     * fill: {"attributeGroupIds": ["uuid", ...], "capturedAt": ISO8601,
     * "masterCategoryId": "uuid"|null}. The async drift handler compares it
     * after a category move; null until the product is first filled.
     *
     * @var array<string, mixed>|null
     */
    private ?array $schemaSnapshot = null;

    /**
     * CHC-04 (#1288) — true when a category move changed this product's
     * effective schema vs its snapshot; cleared on operator acknowledge.
     */
    private bool $schemaDrift = false;

    /**
     * UI-02.6 (#296) — axis definition on a master product row, e.g.
     * `[{"code":"color","attribute_id":"...","values":["red","blue"]}]`.
     * NULL on variant + non-master rows. Only writable on `kind=product`.
     *
     * @var list<array<string, mixed>>|null
     */
    private ?array $variantAxes = null;

    /**
     * IMP-01 (#442) — links every imported object back to the
     * {@see \App\Import\Domain\Entity\ImportSession} that created it.
     * Bare uuid to keep Catalog domain free of an Import-context import.
     * The DB-level FK with `ON DELETE SET NULL` (see migration
     * Version20260506191124) keeps orphan rows readable after the
     * session is hard-deleted.
     */
    private ?Uuid $importSessionId = null;

    /**
     * VIEW-28 (#559) — denormalized cache of the last BulkSession id
     * that mutated this row. Set by bulk handlers after a successful
     * write; reset to NULL on hard delete of the parent BulkSession
     * (`ON DELETE SET NULL` in the FK).
     */
    private ?Uuid $lastBulkSessionId = null;

    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $updatedAt;

    /**
     * MODR-10 (#932) — optimistic-lock counter bumped by Doctrine via the
     * `@Version` ORM mapping. Every UPDATE adds 1; clients who PATCH
     * with a stale `expectedVersion` are rejected via 409 in
     * {@see \App\Catalog\Application\Command\UpdateCatalogObject\UpdateCatalogObjectHandler}.
     */
    private int $version = 1;

    public function __construct(
        ObjectType $objectType,
        string $code,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->objectType = $objectType;
        $this->kind = $objectType->getKind();
        $this->code = $code;
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
     * @internal stamped by TenantAssignmentListener on prePersist
     */
    public function assignTenant(Tenant $tenant): void
    {
        if (null !== $this->tenant) {
            throw new LogicException('Tenant is already assigned and cannot be reassigned.');
        }

        $this->tenant = $tenant;
        $this->recordThat(new ObjectCreated(
            objectId: $this->id,
            kind: $this->kind,
            code: $this->code,
            tenantId: $tenant->getId(),
        ));
    }

    public function getObjectType(): ObjectType
    {
        return $this->objectType;
    }

    public function getKind(): ObjectKind
    {
        return $this->kind;
    }

    /**
     * ADR-015 — the ObjectType tree this category belongs to (NULL for
     * non-category rows).
     */
    public function getCategoryTargetObjectType(): ?ObjectType
    {
        return $this->categoryTargetObjectType;
    }

    /**
     * ADR-015 — assign this category to a categorizable ObjectType's tree.
     * Set once at create time (the create handler enforces presence +
     * `is_categorizable` for `kind='category'`).
     */
    public function scopeCategoryTo(ObjectType $objectType): void
    {
        $this->categoryTargetObjectType = $objectType;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function assignParent(?self $parent): void
    {
        $this->parent = $parent;
        $this->touch();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function changeEnabled(bool $enabled): void
    {
        if ($this->enabled === $enabled) {
            return;
        }

        $this->enabled = $enabled;
        $this->touch();

        if (null !== $this->tenant) {
            $this->recordThat(new ObjectEnabledChanged(
                objectId: $this->id,
                tenantId: $this->tenant->getId(),
                enabled: $enabled,
            ));
        }
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Marking-store reader for the `object_editorial` state machine
     * (ADR-0029). The marking property is deliberately named
     * `editorialMarking` (not `status`) so Symfony property-info never
     * mistakes the writer below for a `status` mutator — that would
     * flip the public `status` field from readOnly string to a
     * writable object in the exported OpenAPI schema.
     */
    #[Ignore]
    public function getEditorialMarking(): string
    {
        return $this->status;
    }

    /**
     * Marking-store writer — called by Symfony Workflow's
     * MethodMarkingStore on `apply()`. Application code MUST NOT call
     * this directly; go through the workflow (`can()`/`apply()`) so
     * topology and guards are enforced. Non-editorial write paths
     * (import/seed) use {@see forceStatus()} instead.
     *
     * @param array<string, mixed> $context transition context passed to
     *                                      `Workflow::apply()` (comment, actor…) — consumed by the
     *                                      transition-log listener (WFL-P0-04), unused here
     */
    #[Ignore]
    public function setEditorialMarking(string $status, array $context = []): void
    {
        unset($context);

        if ($this->status === $status) {
            return;
        }

        $this->status = $status;
        $this->touch();

        if (null === $this->tenant) {
            return;
        }

        if (self::STATUS_PUBLISHED === $status) {
            $this->recordThat(new ObjectPublished(
                objectId: $this->id,
                tenantId: $this->tenant->getId(),
            ));
        } elseif (self::STATUS_ARCHIVED === $status) {
            $this->recordThat(new ObjectArchived(
                objectId: $this->id,
                tenantId: $this->tenant->getId(),
            ));
        }
    }

    /**
     * Deliberate state-machine bypass for non-editorial write paths —
     * import/integration/seeding set status regardless of editorial
     * state (ADR-0029 pillar 8: an integration must never get stuck on
     * review). Editorial paths (API PATCH, transition endpoints) go
     * through the `object_editorial` workflow instead.
     *
     * @internal infrastructure paths only (import, fixtures, benchmarks)
     */
    public function forceStatus(string $status): void
    {
        $this->setEditorialMarking($status);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCompleteness(): array
    {
        return $this->completeness;
    }

    /**
     * @param array<string, mixed> $completeness
     */
    public function recordCompleteness(array $completeness): void
    {
        $this->completeness = $completeness;
        $global = $completeness['global'] ?? null;
        if (\is_int($global)) {
            $this->completenessPct = max(0, min(100, $global));
        }
        $this->touch();
    }

    public function getCompletenessPct(): int
    {
        return $this->completenessPct;
    }

    public function getSyncStatusAggregate(): string
    {
        return $this->syncStatusAggregate;
    }

    public function recordSyncStatusAggregate(string $status): void
    {
        if (!\in_array($status, ['green', 'yellow', 'red', 'gray'], true)) {
            throw new LogicException(\sprintf('Invalid sync_status_aggregate value "%s".', $status));
        }
        if ($this->syncStatusAggregate === $status) {
            return;
        }
        $this->syncStatusAggregate = $status;
        $this->touch();
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributesIndexed(): array
    {
        return $this->attributesIndexed;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function updateAttributeIndex(array $attributes): void
    {
        $previous = $this->attributesIndexed;
        $this->attributesIndexed = $attributes;
        $this->touch();

        if (null === $this->tenant) {
            return;
        }

        $changedCodes = array_values(array_unique(array_merge(
            array_keys(array_diff_key($attributes, $previous)),
            array_keys(array_diff_key($previous, $attributes)),
            array_keys(array_filter(
                $attributes,
                static fn ($value, string $key) => isset($previous[$key]) && $previous[$key] !== $value,
                ARRAY_FILTER_USE_BOTH,
            )),
        )));

        if ([] === $changedCodes) {
            return;
        }

        $this->recordThat(new ObjectAttributesChanged(
            objectId: $this->id,
            tenantId: $this->tenant->getId(),
            changedAttributeCodes: $changedCodes,
            objectTypeId: $this->objectType->getId(),
        ));
    }

    /**
     * Record that this object's master-category set changed (#1314, superseding
     * the primary-only event from CHC-07). Called by the assignment repository
     * after any category mutation; the buffered event is dispatched by
     * {@see \App\Shared\Infrastructure\Messenger\DomainEventDispatcher} once the
     * surrounding flush succeeds, then reconciles channel placements off-thread
     * (the product lands on every channel mapped by ANY of its categories).
     */
    public function recordCategoriesChanged(): void
    {
        if (null === $this->tenant) {
            throw new LogicException('Cannot record category change before the tenant is set.');
        }

        $this->recordThat(new ObjectCategoriesChanged(
            objectId: $this->id,
            tenantId: $this->tenant->getId(),
        ));
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function attachToPath(?string $path): void
    {
        $this->path = $path;
        $this->touch();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSchemaSnapshot(): ?array
    {
        return $this->schemaSnapshot;
    }

    /**
     * @param array<string, mixed>|null $snapshot
     */
    public function recordSchemaSnapshot(?array $snapshot): void
    {
        $this->schemaSnapshot = $snapshot;
    }

    public function getSchemaDrift(): bool
    {
        return $this->schemaDrift;
    }

    public function flagSchemaDrift(bool $drifted): void
    {
        $this->schemaDrift = $drifted;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function getVariantAxes(): ?array
    {
        return $this->variantAxes;
    }

    /**
     * @param list<array<string, mixed>>|null $axes
     */
    public function declareVariantAxes(?array $axes): void
    {
        $this->variantAxes = $axes;
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

    public function getCreatedBy(): ?string
    {
        return $this->createdBy;
    }

    public function getUpdatedBy(): ?string
    {
        return $this->updatedBy;
    }

    public function stampCreatedBy(?string $actor): void
    {
        $this->createdBy = $actor;
    }

    public function stampUpdatedBy(?string $actor): void
    {
        $this->updatedBy = $actor;
    }

    /**
     * #1207 / #1146 — replace the indexed map for a read-only response overlay
     * (system attributes, locale/channel overlay). Unlike
     * {@see updateAttributeIndex()} it does NOT touch `updatedAt` nor record a
     * domain event, so it is safe to call on a detached clone inside a GET
     * provider without spurious side effects or accidental persistence.
     *
     * @param array<string, mixed> $attributes
     */
    public function overlayAttributesIndexedForRead(array $attributes): void
    {
        $this->attributesIndexed = $attributes;
    }

    /**
     * MODR-10 (#932) — optimistic-lock counter; auto-incremented by
     * Doctrine on every flush of a mutated row.
     */
    public function getVersion(): int
    {
        return $this->version;
    }

    public function getImportSessionId(): ?Uuid
    {
        return $this->importSessionId;
    }

    public function assignImportSession(?Uuid $sessionId): void
    {
        $this->importSessionId = $sessionId;
    }

    public function getLastBulkSessionId(): ?Uuid
    {
        return $this->lastBulkSessionId;
    }

    /**
     * VIEW-28 (#559) — bulk handlers call this immediately after writing
     * to flag the row as touched by the given session. Also bumps
     * `updatedAt` so listeners and audit pipelines see a fresh edit.
     */
    public function markTouchedByBulkSession(Uuid $sessionId): void
    {
        $this->lastBulkSessionId = $sessionId;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
