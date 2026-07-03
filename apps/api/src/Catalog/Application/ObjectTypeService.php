<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\Exception\BuiltInObjectTypeException;
use App\Catalog\Domain\Exception\DisabledFeatureException;
use App\Catalog\Domain\Exception\ObjectTypeCodeConflictException;
use App\Catalog\Domain\Exception\ObjectTypeHasInstancesException;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeAttributeRepositoryInterface;
use App\Catalog\Domain\Rule\CompareRule;
use App\Catalog\Domain\Rule\CrossFieldRules;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

/**
 * Application service that owns ObjectType lifecycle invariants.
 *
 * Three guards live here, deliberately at service-level rather than schema:
 *
 *   1. **Custom kinds gated by feature flag.** The
 *      `pim.catalog.enable_custom_object_types` parameter (default false in
 *      MVP) blocks creation of `kind=custom` ObjectTypes. Phase 2 unlocks
 *      it together with the agent tool `create_object_type`. Mitigation
 *      for risk R-29 (over-engineering: custom kinds without UX support).
 *
 *   2. **Built-in deletion blocked.** Predefined Product / Category /
 *      Asset rows (`is_built_in=true`) cannot be removed by service calls.
 *      Phase-2 RLS will enforce the same at the database level; until then
 *      this is the only barrier between an admin and accidental tenant
 *      catalog destruction.
 *
 *   3. **Custom delete with live instances refused** (VIEW-01 #372).
 *      The Danger zone in modeling UI guards this client-side; the API
 *      enforces the same invariant authoritatively via DBAL count.
 *
 * Junction lifecycle (`assignAttribute`, `unassignAttribute`) lives here
 * too so callers do not poke at `ObjectTypeAttribute` directly.
 */
final readonly class ObjectTypeService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ObjectTypeAttributeRepositoryInterface $junctions,
        private AttributeRepositoryInterface $attributes,
        private Connection $connection,
        private bool $enableCustomObjectTypes,
    ) {
    }

    /**
     * @param array<string, string> $label
     */
    public function create(
        string $code,
        ObjectKind $kind,
        array $label,
        bool $builtIn = false,
        ?string $icon = null,
        ?string $color = null,
        bool $hierarchical = false,
        bool $hasVariants = false,
        bool $abstract = false,
    ): ObjectType {
        if (ObjectKind::Custom === $kind && !$this->enableCustomObjectTypes) {
            throw DisabledFeatureException::customObjectTypesDisabled();
        }

        $objectType = new ObjectType($code, $kind, $label);
        if ($builtIn) {
            $objectType->markBuiltIn();
        }
        if (null !== $icon) {
            $objectType->setIcon($icon);
        }
        if (null !== $color) {
            $objectType->setColor($color);
        }
        if ($hierarchical) {
            $objectType->setHierarchical(true);
        }
        if ($hasVariants) {
            $objectType->setHasVariants(true);
        }
        if ($abstract) {
            $objectType->setAbstract(true);
        }

        $this->em->persist($objectType);
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            throw new ObjectTypeCodeConflictException($code);
        }

        return $objectType;
    }

    /**
     * VIEW-01 (#372) — partial update over the modeling Detail view.
     *
     * Built-in rows can only mutate `label`, `icon`, `color`. All other
     * fields throw `BuiltInObjectTypeException::fieldLocked()` so a client
     * crafting a payload directly cannot bypass the FE's locked toggles.
     *
     * Pass `null` for fields that should not change. Pass an explicit value
     * (including empty list / map) to overwrite. The signature uses named
     * arguments so callers don't need to position-track the optionals.
     *
     * @param array<string, string>|null      $label
     * @param list<string>|null               $allowedParentTypeIds
     * @param array<string, mixed>|null       $completenessRules
     * @param list<array<string, mixed>>|null $validationRules      DP-07 (#2037, ADR-0025) cross-field rules
     */
    public function update(
        ObjectType $objectType,
        ?array $label = null,
        ?string $icon = null,
        ?string $color = null,
        ?bool $hierarchical = null,
        ?bool $hasVariants = null,
        ?bool $abstract = null,
        ?array $allowedParentTypeIds = null,
        ?array $completenessRules = null,
        ?array $validationRules = null,
        ?bool $exposeToMainMenu = null,
        ?bool $isCategorizable = null,
        ?bool $hasMultimedia = null,
    ): ObjectType {
        $isBuiltIn = $objectType->isBuiltIn();

        if (null !== $label) {
            $objectType->rename($label);
        }
        if (null !== $icon) {
            $objectType->setIcon($icon);
        }
        if (null !== $color) {
            $objectType->setColor($color);
        }

        // Structural flags (`hierarchical`, `abstract`, `allowedParentTypeIds`,
        // `completenessRules`) remain locked for built-in rows because they
        // shape the entity model itself. The three capability flags below
        // (`hasVariants`, `isCategorizable`, `hasMultimedia`) drive *which
        // tabs* the operator sees in a detail page and are deliberately
        // user-editable on Product too — UX-03 unlock per operator decision.
        if (null !== $hierarchical) {
            if ($isBuiltIn && $objectType->isHierarchical() !== $hierarchical) {
                throw BuiltInObjectTypeException::fieldLocked($objectType, 'hierarchical');
            }
            $objectType->setHierarchical($hierarchical);
        }
        if (null !== $hasVariants) {
            $objectType->setHasVariants($hasVariants);
        }
        if (null !== $abstract) {
            if ($isBuiltIn && $objectType->isAbstract() !== $abstract) {
                throw BuiltInObjectTypeException::fieldLocked($objectType, 'abstract');
            }
            $objectType->setAbstract($abstract);
        }
        if (null !== $allowedParentTypeIds) {
            if ($isBuiltIn && $objectType->getAllowedParentTypeIds() !== $allowedParentTypeIds) {
                throw BuiltInObjectTypeException::fieldLocked($objectType, 'allowedParentTypeIds');
            }
            $objectType->setAllowedParentTypeIds($allowedParentTypeIds);
        }
        if (null !== $completenessRules) {
            if ($isBuiltIn && $objectType->getCompletenessRules() !== $completenessRules) {
                throw BuiltInObjectTypeException::fieldLocked($objectType, 'completenessRules');
            }
            $objectType->updateCompletenessRules($completenessRules);
        }
        if (null !== $validationRules) {
            // DP-07 (#2037, ADR-0025) — deliberately editable on built-in
            // ObjectTypes too: cross-field rules constrain VALUES, not the
            // entity model, and the primary use case (weight_net <=
            // weight_gross) lives on the built-in Product. Shape parsed
            // strictly; referenced codes must exist tenant-wide; compare
            // sides must be numeric attributes of the same type.
            $parsed = CrossFieldRules::fromArray($validationRules);
            $this->assertRuleReferences($objectType, $parsed);
            $objectType->updateValidationRules(CrossFieldRules::toStoredArray($parsed));
        }
        if (null !== $exposeToMainMenu) {
            // VIEW-08 (#427): Asset has its own /assets DAM page. Exposing it
            // as a generic menu candidate would route to /objects/asset which
            // 404s in MVP (B-2 ships the generic listing). Block at write
            // time so the FE toggle and the backend agree on the rule.
            if ($exposeToMainMenu && ObjectKind::Asset === $objectType->getKind()) {
                throw new LogicException(
                    'Asset ObjectType cannot be exposed to main menu — use the dedicated /assets DAM page instead.',
                );
            }
            $objectType->setExposeToMainMenu($exposeToMainMenu);
        }
        if (null !== $isCategorizable) {
            // ADR-014 / MOD-11 (#903): a category that participates in its
            // own attribute overlay creates a circular dependency with the
            // resolver. Other kinds (Product, Asset, custom) are free to
            // flip; built-in `is_built_in=true` rows DO NOT lock this flag
            // — operator may want Asset to become categorizable later.
            if ($isCategorizable && ObjectKind::Category === $objectType->getKind()) {
                throw new LogicException(
                    'Category ObjectType cannot be flagged as categorizable — categories drive the overlay, they don\'t consume it.',
                );
            }
            $objectType->setCategorizable($isCategorizable);
        }
        if (null !== $hasMultimedia) {
            // UX-03 (#1049): capability flags (hasVariants / isCategorizable /
            // hasMultimedia) gate *which tabs* the operator sees, not the
            // entity model — so they stay flippable on built-in rows too,
            // otherwise built-in Product is forever stuck on its seed value.
            // (Reverts the UP-07b built-in-Product lock, which regressed this
            // contract and broke updateUnlocksCapabilityFlagsOnBuiltInProduct.)
            //
            // UX-03: Asset is itself a multimedia object; promoting an Asset
            // ObjectType to host a Multimedia tab creates a recursive UI
            // (the multimedia tab would list assets pointing at assets).
            // Symmetric to the Category exclusion above.
            if ($hasMultimedia && ObjectKind::Asset === $objectType->getKind()) {
                throw new LogicException(
                    'Asset ObjectType cannot have a Multimedia capability — the DAM workflow is the multimedia surface itself.',
                );
            }
            $objectType->setHasMultimedia($hasMultimedia);
        }

        $this->em->flush();

        return $objectType;
    }

    /**
     * DP-07 (#2037) — every code a rule references must exist tenant-wide
     * (deliberately NOT the effective schema: group membership drifts
     * independently of rules and a detach must not silently break them),
     * and compare sides must be numeric attributes of the SAME type
     * (no price-vs-metric comparisons; unit conversion is out of scope).
     *
     * @param list<\App\Catalog\Domain\Rule\CrossFieldRule> $rules
     */
    private function assertRuleReferences(ObjectType $objectType, array $rules): void
    {
        $tenant = $objectType->getTenant();
        if (null === $tenant) {
            throw new LogicException('ObjectType has no tenant — cannot validate rule references.');
        }

        $numericTypes = [AttributeType::Number, AttributeType::Metric, AttributeType::Price];

        foreach ($rules as $rule) {
            $resolved = [];
            foreach ($rule->referencedCodes() as $code) {
                $attribute = $this->attributes->findByCode($code, $tenant);
                if (!$attribute instanceof Attribute) {
                    throw new LogicException(\sprintf('validation_rules references unknown attribute "%s".', $code));
                }
                $resolved[$code] = $attribute;
            }

            if ($rule instanceof CompareRule) {
                $left = $resolved[$rule->left];
                $right = $resolved[$rule->right];
                if (!\in_array($left->getType(), $numericTypes, true)) {
                    throw new LogicException(\sprintf('compare.left "%s" must be a numeric attribute (number/metric/price), got "%s".', $rule->left, $left->getType()->value));
                }
                if ($left->getType() !== $right->getType()) {
                    throw new LogicException(\sprintf('compare sides must share one attribute type — "%s" is %s, "%s" is %s.', $rule->left, $left->getType()->value, $rule->right, $right->getType()->value));
                }
            }
        }
    }

    public function delete(ObjectType $objectType): void
    {
        if ($objectType->isBuiltIn()) {
            throw BuiltInObjectTypeException::cannotDelete($objectType);
        }

        $instanceCount = $this->countInstances($objectType);
        if ($instanceCount > 0) {
            throw new ObjectTypeHasInstancesException($objectType, $instanceCount);
        }

        // AUD-072 (#1614) — safety-net mirroring DeleteAttributeHandler: the
        // pre-check above can race with a concurrent insert (an object of this
        // type, or any other FK referencing it) between the COUNT and the
        // DELETE. Postgres then raises a FK violation that otherwise escapes as
        // a 500. Translate it into the same 409-mapped domain exception the
        // pre-check uses, so the API answers a consistent ConflictHttpException
        // instead of a raw server error. Re-count for an accurate message;
        // fall back to the FK signal (count 1) if the re-count also fails.
        try {
            $this->em->remove($objectType);
            $this->em->flush();
        } catch (ForeignKeyConstraintViolationException $e) {
            throw new ObjectTypeHasInstancesException($objectType, max(1, $this->countInstances($objectType)), $e);
        }
    }

    /**
     * VIEW-01 (#372) — clone an existing ObjectType into a new custom row,
     * copying icon/color/settings + attached AttributeGroup junctions but
     * starting with a fresh schema_version=1 and an empty `objects` set.
     *
     * Built-in source allowed (e.g. clone product → new "Product Pro").
     * Result is always custom (`kind=Custom`) regardless of source kind.
     * Caller (controller) supplies a unique code + label for the new row.
     *
     * @param array<string, string> $newLabel
     */
    public function duplicate(
        ObjectType $source,
        string $newCode,
        array $newLabel,
    ): ObjectType {
        if (!$this->enableCustomObjectTypes) {
            throw DisabledFeatureException::customObjectTypesDisabled();
        }

        $clone = new ObjectType($newCode, ObjectKind::Custom, $newLabel);
        if (null !== $source->getIcon()) {
            $clone->setIcon($source->getIcon());
        }
        if (null !== $source->getColor()) {
            $clone->setColor($source->getColor());
        }
        $clone->setHierarchical($source->isHierarchical());
        $clone->setHasVariants($source->hasVariants());
        $clone->setAbstract($source->isAbstract());
        $clone->setAllowedParentTypeIds($source->getAllowedParentTypeIds());
        $clone->updateCompletenessRules($source->getCompletenessRules());

        $this->em->persist($clone);
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            throw new ObjectTypeCodeConflictException($newCode);
        }

        return $clone;
    }

    /**
     * Idempotent attribute assignment: existing junction is updated in
     * place, missing one is created. Returns the live junction so callers
     * can read back the persisted defaults.
     */
    public function assignAttribute(
        ObjectType $objectType,
        Attribute $attribute,
        bool $required = false,
        int $sortOrder = 0,
    ): ObjectTypeAttribute {
        $junction = $this->junctions->findOne($objectType, $attribute);
        if (null === $junction) {
            $junction = new ObjectTypeAttribute($objectType, $attribute, $required, $sortOrder);
            $this->em->persist($junction);
        } else {
            $junction->changeRequiredForCompleteness($required);
            $junction->reorder($sortOrder);
        }

        $this->em->flush();

        return $junction;
    }

    public function unassignAttribute(ObjectType $objectType, Attribute $attribute): void
    {
        $junction = $this->junctions->findOne($objectType, $attribute);
        if (null === $junction) {
            return;
        }

        $this->em->remove($junction);
        $this->em->flush();
    }

    /**
     * Cheap DBAL count for the delete guard. Avoids hydrating the full
     * objects collection — VIEW-01 detail view uses UsageQueryService for
     * the same number, but the cache TTL there means a stale read could
     * let a delete slip through; we re-check at write time.
     */
    private function countInstances(ObjectType $objectType): int
    {
        $raw = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM objects WHERE object_type_id = ?',
            [$objectType->getId()->toRfc4122()],
        );

        return \is_scalar($raw) ? (int) $raw : 0;
    }
}
