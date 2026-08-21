<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * #2942 — per-tenant seeder for the `name` Attribute plus the
 * `label_attribute_id` pointer on the three built-in ObjectTypes.
 *
 * Why this exists: {@see ObjectAttributesUpserter} silently drops payload
 * keys whose attribute code does not exist in the tenant (by design, #45 —
 * the import path relies on it). `name` used to be created only by
 * {@see DemoCatalogSeeder}, so a tenant provisioned from the platform panel
 * came up without it. Every `POST /api/categories` carrying
 * `attributes: {name: "Książki"}` then wrote nothing, `attributes_indexed`
 * stayed empty, and the category tree fell back to the raw snake_case code —
 * the operator saw the name they typed replaced by `ksiazki`.
 *
 * `name` is not marked `is_system`: it is the operator's display label, it
 * belongs in the attribute library and must stay editable and groupable
 * (contrast with the audit attributes in
 * {@see BuiltInSystemAttributesSeeder}). It is also NOT required — an
 * existing tenant must not start rejecting writes that were valid a
 * deploy ago; requiring a name is tenant modeling, not a platform default.
 *
 * No AttributeGroup and no `object_type_attributes` junction are created:
 * form visibility stays explicit modeling configuration, exactly like the
 * system attributes. The tree label, {@see GetObjectSummaryHandler} and the
 * categories export read `attributes_indexed` / `label_attribute_id`
 * directly, so the Attribute row plus the pointer is all they need.
 *
 * Run order in the onboarding pipeline
 * ({@see TenantCatalogBootstrapper::bootstrap}):
 *   1. {@see BuiltInObjectTypeSeeder::seed}   ← must exist to be pointed at
 *   2. {@see BuiltInSystemAttributesSeeder::seed}
 *   3. {@see self::seed}                      ← here
 *
 * Idempotent: the attribute is looked up by `(tenant, code)` and the pointer
 * is only written when the ObjectType has none, so a re-run over a tenant
 * that already models its own label attribute leaves that choice alone.
 */
final readonly class BuiltInLabelAttributeSeeder
{
    public const string CODE = 'name';

    /** @var array<string, string> */
    private const array LABEL = ['pl' => 'Nazwa', 'en' => 'Name'];

    /**
     * ObjectTypes whose display label defaults to `name`.
     *
     * @var list<ObjectKind>
     */
    private const array LABELLED_KINDS = [
        ObjectKind::Product,
        ObjectKind::Category,
        ObjectKind::Asset,
    ];

    public function __construct(
        private AttributeRepositoryInterface $attributeRepository,
        private ObjectTypeRepositoryInterface $objectTypeRepository,
        private EntityManagerInterface $em,
        private TenantContext $tenantContext,
    ) {
    }

    /**
     * Seed the `name` Attribute and point the built-in ObjectTypes at it.
     * Returns the number of attribute rows actually created (0 = no-op).
     */
    public function seed(Tenant $tenant): int
    {
        $previous = $this->tenantContext->get();
        $this->tenantContext->set($tenant);

        try {
            $created = 0;
            $attribute = $this->attributeRepository->findByCode(self::CODE, $tenant);
            if (!$attribute instanceof Attribute) {
                $attribute = new Attribute(self::CODE, self::LABEL, AttributeType::Text);
                $attribute->changeLocalizable(true);
                $attribute->updateValidationRules(['max_length' => 255]);
                $attribute->reorder(0);
                $this->em->persist($attribute);
                $this->em->flush();
                $created = 1;
            }

            $assigned = false;
            foreach (self::LABELLED_KINDS as $kind) {
                $objectType = $this->objectTypeRepository->findBuiltInByKind($kind, $tenant);
                if (null === $objectType || null !== $objectType->getLabelAttribute()) {
                    continue;
                }

                $objectType->assignLabelAttribute($attribute);
                $assigned = true;
            }

            if ($assigned) {
                $this->em->flush();
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
