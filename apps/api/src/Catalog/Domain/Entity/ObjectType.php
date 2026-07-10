<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Entity;

use App\Catalog\Domain\ObjectKind;
use App\Shared\Application\TenantScoped;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Generic template for every domain object kind in PIM (per ADR-009).
 *
 * Predefined kinds (`Product`, `Category`, `Asset`) seed as fixtures with
 * `is_built_in=true` in #33 — they are platform-owned and protected from
 * deletion by `ObjectTypeService::delete`. Custom kinds (`Customer`,
 * `Supplier`, `PriceList`, etc.) are unlocked in phase 2/3 via the
 * `enable_custom_object_types` feature flag.
 *
 * `label_attribute_id` and `image_attribute_id` are nullable foreign keys
 * to `attributes` — the convention is "name" → display label, "main_image"
 * → image. Cross-ObjectType reference is technically possible but should
 * be paired with the junction; a validator that enforces this lands as a
 * follow-up after #34.
 *
 * `completeness_rules` is a JSONB payload stored verbatim. The Doctrine
 * listener that interprets it (e.g. `{required: ['sku', 'name'], weight:
 * {sku: 2, name: 1}}`) and computes `Object.completeness_pct` is in #38.
 *
 * `schema_version` is a forward hook for export/import tooling planned
 * for phase 2 (when enterprise customers move definitions between
 * environments) — set on every save, bumped manually when a schema-
 * breaking change to `completeness_rules` ships.
 */
class ObjectType implements TenantScoped
{
    private Uuid $id;
    private ?Tenant $tenant = null;
    #[Assert\NotBlank]
    #[Assert\Length(max: 128)]
    private string $code;
    private ObjectKind $kind;

    private bool $isBuiltIn = false;
    private bool $codeImmutable = false;
    private bool $deletable = true;

    #[Assert\Length(max: 64)]
    private ?string $icon = null;

    #[Assert\Length(max: 16)]
    private ?string $color = null;

    /**
     * @var array<string, string>
     */
    #[Assert\Type('array')]
    private array $label;

    /**
     * @var array<string, mixed>
     */
    private array $completenessRules = [];

    /**
     * DP-07 (#2037, ADR-0025) — cross-field rules (`compare` / `require_when`)
     * stored as a JSONB list; shape guarded by CrossFieldRules::fromArray at
     * the write edge (ObjectTypeService), read by CrossFieldRulesValidator.
     *
     * @var list<array<string, mixed>>
     */
    private array $validationRules = [];

    /**
     * WFL-P1-04 (#2418) — completeness gate on publish/approve
     * transitions (ADR-0029 pillar 6). NULL = gate off (default).
     * Shape (docs/api/jsonb-schemas.md §6): {enabled: bool,
     * min_completeness_pct: int 0-100, scope: 'global'|'per_channel',
     * channels?: list<string>}.
     *
     * @var array<string, mixed>|null
     */
    private ?array $workflowPublishGate = null;
    private ?Attribute $labelAttribute = null;
    private ?Attribute $imageAttribute = null;

    /**
     * Configurable behavior toggles surfaced in the modeling UI per VIEW-01
     * (#372). Default `false`; built-in seeders set `hasVariants` for product
     * and `hierarchical` for category. Built-in rows have these fields locked
     * by the voter, custom rows expose them in the Settings card.
     */
    private bool $hierarchical = false;
    private bool $hasVariants = false;
    private bool $abstract = false;

    /**
     * VIEW-08 (#427) — gating flag for main menu candidacy. When TRUE, the
     * ObjectType appears as a candidate in `/settings/menu` (Available
     * section) and can be promoted to Visible via `MenuConfiguration`. The
     * actual ordering and per-tenant visible flag lives in
     * `menu_configurations.items`, not here.
     *
     * Built-in `product` is seeded with `exposeToMainMenu=true` so the
     * default sidebar reproduces the legacy hard-coded "Produkty" entry.
     * `kind=Asset` is rejected at the service level (`/assets` DAM has its
     * own dedicated page; the generic listing route would 404 in MVP).
     *
     * ADR-014 / MOD-01 (#893): this is the canonical "show in main menu"
     * capability flag; the ADR's `show_in_main_menu` name maps to this
     * existing column (no rename).
     */
    private bool $exposeToMainMenu = false;

    /**
     * ADR-014 / MOD-01 (#893) — gates participation in primary-category
     * driven attribute distribution. When TRUE, an instance of this
     * ObjectType:
     *   - is required to have exactly one primary `ObjectCategory` link;
     *   - inherits attribute groups cumulatively from the root→leaf path
     *     of that primary category (per `EffectiveAttributeGroupResolver`
     *     after MOD-03);
     *   - appears in `/modeling/categories` ObjectType filter.
     * When FALSE, instances bypass category overlay entirely — only the
     * ObjectType's base attribute groups apply.
     *
     * Seeded `true` for `kind=product`; `false` for category/asset and any
     * future built-in kinds. Custom ObjectTypes default to `false` and the
     * tenant flips it explicitly in the Settings card.
     */
    private bool $isCategorizable = false;

    /**
     * UP-00 (#1017) — gates the Multimedia tab on the UniversalDetailPage
     * (UP-07). Built-in product seeded `true` to match legacy hard-coded
     * behaviour; other built-ins + custom kinds default `false`. The
     * operator opts in per ObjectType via the UP-07b wizard toggle.
     *
     * `kind=product` is implicitly locked at the wizard level — built-in
     * lock pattern. Other kinds (including future built-in additions) are
     * free to flip the flag.
     */
    private bool $hasMultimedia = false;

    /**
     * UUID list of ObjectTypes allowed as parent. Plain JSONB list (not a
     * junction) — N stays small (≤ 5 typical), and the only consumer is the
     * detail view's Allowed parent types chip strip. A junction would cost
     * us a roundtrip per detail page load for no gain at this cardinality.
     *
     * @var list<string>
     */
    private array $allowedParentTypeIds = [];

    private int $schemaVersion = 1;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $updatedAt;

    /**
     * @param array<string, string> $label
     */
    public function __construct(
        string $code,
        ObjectKind $kind,
        array $label,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->code = $code;
        $this->kind = $kind;
        $this->label = $label;
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
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getKind(): ObjectKind
    {
        return $this->kind;
    }

    public function isBuiltIn(): bool
    {
        return $this->isBuiltIn;
    }

    public function markBuiltIn(): void
    {
        $this->isBuiltIn = true;
        $this->touch();
    }

    public function isCodeImmutable(): bool
    {
        return $this->codeImmutable;
    }

    public function lockCode(): void
    {
        $this->codeImmutable = true;
        $this->touch();
    }

    public function isDeletable(): bool
    {
        return $this->deletable;
    }

    public function markUndeletable(): void
    {
        $this->deletable = false;
        $this->touch();
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): void
    {
        $this->icon = $icon;
        $this->touch();
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): void
    {
        $this->color = $color;
        $this->touch();
    }

    /**
     * @throws LogicException when code_immutable is set
     */
    public function changeCode(string $code): void
    {
        if ($this->codeImmutable) {
            throw new LogicException('ObjectType code is immutable for this row.');
        }

        $this->code = $code;
        $this->touch();
    }

    /**
     * @return array<string, string>
     */
    public function getLabel(): array
    {
        return $this->label;
    }

    /**
     * @param array<string, string> $label
     */
    public function rename(array $label): void
    {
        $this->label = $label;
        $this->touch();
    }

    /**
     * @return array<string, mixed>
     */
    public function getCompletenessRules(): array
    {
        return $this->completenessRules;
    }

    /**
     * @param array<string, mixed> $rules
     */
    public function updateCompletenessRules(array $rules): void
    {
        $this->completenessRules = $rules;
        $this->touch();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getValidationRules(): array
    {
        return $this->validationRules;
    }

    /**
     * @param list<array<string, mixed>> $rules canonical stored shape (CrossFieldRules::toStoredArray)
     */
    public function updateValidationRules(array $rules): void
    {
        $this->validationRules = $rules;
        $this->touch();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getWorkflowPublishGate(): ?array
    {
        return $this->workflowPublishGate;
    }

    /**
     * @param array<string, mixed>|null $gate
     *
     * @throws InvalidArgumentException on a malformed gate payload
     */
    public function setWorkflowPublishGate(?array $gate): void
    {
        if (null !== $gate) {
            if (!\is_bool($gate['enabled'] ?? null)) {
                throw new InvalidArgumentException('workflowPublishGate.enabled must be a boolean.');
            }
            $minPct = $gate['min_completeness_pct'] ?? null;
            if (!\is_int($minPct) || $minPct < 0 || $minPct > 100) {
                throw new InvalidArgumentException('workflowPublishGate.min_completeness_pct must be an integer 0-100.');
            }
            $scope = $gate['scope'] ?? 'global';
            if (!\in_array($scope, ['global', 'per_channel'], true)) {
                throw new InvalidArgumentException('workflowPublishGate.scope must be "global" or "per_channel".');
            }
            if ('per_channel' === $scope) {
                $channels = $gate['channels'] ?? null;
                if (!\is_array($channels) || [] === $channels) {
                    throw new InvalidArgumentException('workflowPublishGate.channels must be a non-empty list for per_channel scope.');
                }
                foreach ($channels as $channel) {
                    if (!\is_string($channel) || '' === \trim($channel)) {
                        throw new InvalidArgumentException('workflowPublishGate.channels entries must be non-empty strings.');
                    }
                }
            }
            $known = ['enabled', 'min_completeness_pct', 'scope', 'channels'];
            foreach (\array_keys($gate) as $key) {
                if (!\in_array($key, $known, true)) {
                    throw new InvalidArgumentException(\sprintf('workflowPublishGate: unknown key "%s".', $key));
                }
            }
        }

        $this->workflowPublishGate = $gate;
        $this->touch();
    }

    public function getLabelAttribute(): ?Attribute
    {
        return $this->labelAttribute;
    }

    public function assignLabelAttribute(?Attribute $attribute): void
    {
        $this->labelAttribute = $attribute;
        $this->touch();
    }

    public function getImageAttribute(): ?Attribute
    {
        return $this->imageAttribute;
    }

    public function assignImageAttribute(?Attribute $attribute): void
    {
        $this->imageAttribute = $attribute;
        $this->touch();
    }

    public function isHierarchical(): bool
    {
        return $this->hierarchical;
    }

    public function setHierarchical(bool $value): void
    {
        $this->hierarchical = $value;
        $this->touch();
    }

    public function hasVariants(): bool
    {
        return $this->hasVariants;
    }

    /**
     * Symfony PropertyAccess accessor alias — `hasVariants()` reads naturally
     * in domain code but PropertyAccessor expects `getHasVariants()` /
     * `isHasVariants()` to expose it as the `hasVariants` property in the
     * normalized output.
     */
    public function getHasVariants(): bool
    {
        return $this->hasVariants;
    }

    public function setHasVariants(bool $value): void
    {
        $this->hasVariants = $value;
        $this->touch();
    }

    public function isAbstract(): bool
    {
        return $this->abstract;
    }

    public function setAbstract(bool $value): void
    {
        $this->abstract = $value;
        $this->touch();
    }

    public function isExposedToMainMenu(): bool
    {
        return $this->exposeToMainMenu;
    }

    /**
     * Symfony PropertyAccess accessor — `isExposedToMainMenu()` reads naturally
     * in domain code, but the serializer expects `getExposeToMainMenu()` to
     * surface the property as `exposeToMainMenu` in the JSON output.
     */
    public function getExposeToMainMenu(): bool
    {
        return $this->exposeToMainMenu;
    }

    public function setExposeToMainMenu(bool $value): void
    {
        $this->exposeToMainMenu = $value;
        $this->touch();
    }

    public function isCategorizable(): bool
    {
        return $this->isCategorizable;
    }

    /**
     * Symfony PropertyAccess accessor alias — `getIsCategorizable()` exposes
     * the property as `isCategorizable` in serialized JSON output.
     */
    public function getIsCategorizable(): bool
    {
        return $this->isCategorizable;
    }

    public function setCategorizable(bool $value): void
    {
        $this->isCategorizable = $value;
        $this->touch();
    }

    public function hasMultimedia(): bool
    {
        return $this->hasMultimedia;
    }

    /**
     * Symfony PropertyAccess accessor alias — exposes the property as
     * `hasMultimedia` in serialized JSON output.
     */
    public function getHasMultimedia(): bool
    {
        return $this->hasMultimedia;
    }

    public function setHasMultimedia(bool $value): void
    {
        $this->hasMultimedia = $value;
        $this->touch();
    }

    /**
     * @return list<string>
     */
    public function getAllowedParentTypeIds(): array
    {
        return $this->allowedParentTypeIds;
    }

    /**
     * @param list<string> $ids
     */
    public function setAllowedParentTypeIds(array $ids): void
    {
        $this->allowedParentTypeIds = array_values(array_unique($ids));
        $this->touch();
    }

    public function getSchemaVersion(): int
    {
        return $this->schemaVersion;
    }

    public function bumpSchemaVersion(): void
    {
        ++$this->schemaVersion;
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
