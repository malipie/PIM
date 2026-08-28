<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\RelationCardinality;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ADR-014 / MOD-02 (#894) — per-tenant seeder for the five built-in
 * `relation`-typed Attributes attached to the Product ObjectType
 * (`cross_sell`, `up_sell`, `related`, `alternative`, `accessory`).
 *
 * MODRC-01 (#1080): the legacy seeded "Powiązania" AttributeGroup is no
 * longer created. The 5 relation attributes are attached to the Product
 * ObjectType as loose {@see ObjectTypeAttribute} rows; if an operator
 * wants them visually grouped, they create a custom AttributeGroup and
 * attach the attributes themselves (analogous to the audit attributes
 * after #1074).
 *
 * Run order in the fixtures / onboarding pipeline:
 *   1. {@see BuiltInObjectTypeSeeder::seed}
 *   2. {@see BuiltInSystemAttributesSeeder::seed}
 *   3. {@see self::seed}            ← here
 *
 * Idempotent: each lookup is keyed by `(tenant, code)`; re-runs after the
 * 5 rows exist are no-ops. Attribute → ObjectType junction is also
 * re-checked before insert.
 */
final readonly class BuiltInProductRelationAttributesSeeder
{
    /** Layout offset used when computing per-attribute `sortOrder`. */
    private const int SORT_OFFSET = 500;

    /**
     * @var array<string, array{label: array<string, string>, position: int, advanced: bool}>
     */
    private const array DEFINITIONS = [
        'cross_sell' => [
            'label' => ['pl' => 'Sprzedaż krzyżowa', 'en' => 'Cross-sell'],
            'position' => 10,
            'advanced' => false,
        ],
        'up_sell' => [
            'label' => ['pl' => 'Sprzedaż dodatkowa', 'en' => 'Up-sell'],
            'position' => 20,
            'advanced' => false,
        ],
        'related' => [
            'label' => ['pl' => 'Powiązane', 'en' => 'Related'],
            'position' => 30,
            'advanced' => false,
        ],
        'alternative' => [
            'label' => ['pl' => 'Alternatywne', 'en' => 'Alternative'],
            'position' => 40,
            'advanced' => false,
        ],
        'accessory' => [
            'label' => ['pl' => 'Akcesoria', 'en' => 'Accessory'],
            'position' => 50,
            'advanced' => false,
        ],
    ];

    public function __construct(
        private AttributeRepositoryInterface $attributeRepository,
        private ObjectTypeRepositoryInterface $objectTypeRepository,
        private EntityManagerInterface $em,
        private TenantContext $tenantContext,
    ) {
    }

    /**
     * Seed missing built-in relation attributes for the given tenant.
     * Returns the number of attribute rows actually created.
     */
    public function seed(Tenant $tenant): int
    {
        $previous = $this->tenantContext->get();
        $this->tenantContext->set($tenant);

        try {
            $productType = $this->objectTypeRepository->findBuiltInByKind(ObjectKind::Product, $tenant);
            if (null === $productType) {
                // BuiltInObjectTypeSeeder must run first — if Product is
                // missing the rest of the seed has no anchor to attach to.
                return 0;
            }

            // Seed each missing relation attribute and wire it directly to the
            // Product ObjectType. No AttributeGroup is created — placement is
            // explicit modeling configuration (MODRC-01).
            $created = 0;
            $productTargetIds = [$productType->getId()->toRfc4122()];
            foreach (self::DEFINITIONS as $code => $definition) {
                $existing = $this->attributeRepository->findByCode($code, $tenant);
                if (null !== $existing) {
                    continue;
                }

                // Issue #1092 — relation attributes are NOT marked
                // `is_system` anymore. Operator wants the 5 built-in
                // relation attrs (cross_sell, up_sell, related,
                // alternative, accessory) to behave like normal
                // attributes: addable, removable, groupable into any
                // AttributeGroup. The `markSystem()` call that used to
                // sit here drove the BuiltInLockBadge in
                // /modeling/attributes and refused detachment via
                // `DetachAttributeFromGroupHandler`. Migration
                // Version20260528110000 clears the flag for existing
                // rows.
                $attribute = new Attribute($code, $definition['label'], AttributeType::Relation);
                $attribute->reorder($definition['position']);
                $attribute->setRelationTargetObjectTypeIds($productTargetIds);
                $attribute->setRelationCardinality(RelationCardinality::Many);
                $attribute->setRelationAdvanced($definition['advanced']);
                $this->em->persist($attribute);
                $this->em->flush();

                $this->em->persist(new ObjectTypeAttribute(
                    objectType: $productType,
                    attribute: $attribute,
                    requiredForCompleteness: false,
                    sortOrder: self::SORT_OFFSET + $definition['position'],
                ));
                $this->em->flush();
                ++$created;
            }

            return $created;
        } finally {
            if (null === $previous) {
                $this->tenantContext->clear();
            } else {
                $this->tenantContext->set($previous);
            }
        }
    }
}
