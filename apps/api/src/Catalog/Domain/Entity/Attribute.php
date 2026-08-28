<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Entity;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\RelationCardinality;
use App\Shared\Application\TenantScoped;
use App\Shared\Domain\Tenant;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A single tenant-scoped attribute definition.
 *
 * Attributes describe the shape of `Object` values (per ADR-009 generic
 * ObjectType model). One attribute can be reused across many ObjectTypes
 * via the `object_type_attributes` junction (added in #32) — e.g. reusing
 * `main_image` across product-like kinds. `Asset` / `Category` are closed
 * system kinds (amends ADR-009) and attach no attributes.
 *
 * `validation_rules` is plain JSONB in #31. The per-type interpreter that
 * enforces "min/max for `number`", "max_length for `text`", etc. lands in
 * #39 (0.3.9). Until then it is a structured payload the entity stores
 * verbatim — admins can already configure it; nothing on the server side
 * trips on out-of-range values yet.
 *
 * `group_id` FK is nullable + ON DELETE SET NULL — removing an attribute
 * group leaves its members ungrouped rather than deleting them.
 */
class Attribute implements TenantScoped
{
    private Uuid $id;
    private ?Tenant $tenant = null;
    private ?AttributeGroup $group = null;
    #[Assert\NotBlank]
    #[Assert\Length(max: 64)]
    private string $code;

    /**
     * @var array<string, string>
     */
    #[Assert\Type('array')]
    private array $label;

    /**
     * @var array<string, string>|null
     */
    private ?array $help = null;
    private AttributeType $type;

    private bool $isLocalizable = false;

    private bool $isScopable = false;

    private bool $isRequired = false;

    /**
     * VIEW-38 (#579) — `is_filterable=true` exposes the attribute as a
     * top-level filter target on the catalog Meilisearch index. The
     * indexer denormalizes the envelope's scalar value via
     * {@see DocumentFlattener}, and `MeilisearchIndexProvisioner`
     * unions the codes of every `is_filterable=true` attribute into
     * `filterableAttributes` so a filter expression like
     * `manufacturer = "Bosch"` resolves without a settings deploy.
     */
    private bool $isFilterable = false;

    /**
     * UI-08.3 (#258) — `is_system=true` marks platform-owned attributes
     * (`created_at`, `updated_at`, `created_by`, `updated_by`). They are
     * created by migration / seeder, never deletable, and code immutable.
     * Form visibility is explicit modeling configuration via AttributeGroup
     * membership and ObjectType/Category attachments.
     */
    private bool $isSystem = false;

    /**
     * @var array<string, mixed>
     */
    private array $validationRules = [];

    /**
     * ADR-014 / MOD-01 (#893) — config for attributes of type `relation`.
     *
     * `relationTargetObjectTypeIds` — UUID list of ObjectTypes accepted as
     * link targets. Empty list (default) on a non-relation attribute; on a
     * relation attribute, empty means the editor must constrain at write
     * time (MOD-05 validation).
     *
     * `relationCardinality` — `one` or `many`; NULL for non-relation
     * attributes. Stored as VARCHAR(8) in Postgres with a CHECK constraint
     * limiting values to the enum cases.
     *
     * `relationAdvanced` — when TRUE, every `object_relations` row for
     * this attribute carries metadata fields (`object_relations.metadata
     * JSONB`); MOD-08 wires the metadata schema.
     *
     * @var list<string>
     */
    private array $relationTargetObjectTypeIds = [];

    private ?RelationCardinality $relationCardinality = null;

    private bool $relationAdvanced = false;

    /**
     * MODR-08 (#930) — list of target attribute codes to surface in the
     * relation widget's preview card. Empty list (default) → preview
     * falls back to target object code + name. Meaningful only when
     * `$type === AttributeType::Relation`.
     *
     * @var list<string>
     */
    private array $relationPreviewFields = [];

    private int $position = 0;
    private DateTimeImmutable $createdAt;

    /**
     * @param array<string, string> $label
     */
    public function __construct(
        string $code,
        array $label,
        AttributeType $type,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->code = $code;
        $this->label = $label;
        $this->type = $type;
        $this->createdAt = new DateTimeImmutable();
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

    public function getGroup(): ?AttributeGroup
    {
        return $this->group;
    }

    public function assignToGroup(?AttributeGroup $group): void
    {
        $this->group = $group;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * @throws LogicException when invoked on a system attribute (immutable code per UI-08.3)
     */
    public function changeCode(string $code): void
    {
        if ($this->isSystem) {
            throw new LogicException('System attribute code is immutable.');
        }

        $this->code = $code;
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
    }

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    /**
     * @internal called by seeders / migrations. Once set, can never be unset.
     */
    public function markSystem(): void
    {
        $this->isSystem = true;
    }

    /**
     * @return array<string, string>|null
     */
    public function getHelp(): ?array
    {
        return $this->help;
    }

    /**
     * @param array<string, string>|null $help
     */
    public function updateHelp(?array $help): void
    {
        $this->help = $help;
    }

    public function getType(): AttributeType
    {
        return $this->type;
    }

    public function isLocalizable(): bool
    {
        return $this->isLocalizable;
    }

    public function changeLocalizable(bool $localizable): void
    {
        $this->isLocalizable = $localizable;
    }

    public function isScopable(): bool
    {
        return $this->isScopable;
    }

    public function changeScopable(bool $scopable): void
    {
        $this->isScopable = $scopable;
    }

    public function isRequired(): bool
    {
        return $this->isRequired;
    }

    public function changeRequired(bool $required): void
    {
        $this->isRequired = $required;
    }

    public function isFilterable(): bool
    {
        return $this->isFilterable;
    }

    public function changeFilterable(bool $filterable): void
    {
        $this->isFilterable = $filterable;
    }

    /**
     * @return array<string, mixed>
     */
    public function getValidationRules(): array
    {
        return $this->validationRules;
    }

    /**
     * @param array<string, mixed> $rules
     */
    public function updateValidationRules(array $rules): void
    {
        $this->validationRules = $rules;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function reorder(int $position): void
    {
        $this->position = $position;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function usesOptions(): bool
    {
        return $this->type->usesOptions();
    }

    /**
     * @return list<string>
     */
    public function getRelationTargetObjectTypeIds(): array
    {
        return $this->relationTargetObjectTypeIds;
    }

    /**
     * @param list<string> $ids
     */
    public function setRelationTargetObjectTypeIds(array $ids): void
    {
        $this->relationTargetObjectTypeIds = array_values(array_unique($ids));
    }

    public function getRelationCardinality(): ?RelationCardinality
    {
        return $this->relationCardinality;
    }

    public function setRelationCardinality(?RelationCardinality $cardinality): void
    {
        $this->relationCardinality = $cardinality;
    }

    public function isRelationAdvanced(): bool
    {
        return $this->relationAdvanced;
    }

    public function setRelationAdvanced(bool $advanced): void
    {
        $this->relationAdvanced = $advanced;
    }

    /**
     * @return list<string>
     */
    public function getRelationPreviewFields(): array
    {
        return $this->relationPreviewFields;
    }

    /**
     * @param list<string> $fields
     */
    public function setRelationPreviewFields(array $fields): void
    {
        $normalized = [];
        foreach ($fields as $field) {
            if ('' === $field) {
                continue;
            }
            if (\in_array($field, $normalized, true)) {
                continue;
            }
            $normalized[] = $field;
        }
        $this->relationPreviewFields = $normalized;
    }
}
