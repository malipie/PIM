<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * UI-08.3 (#258) — runtime per-tenant seeder for the system attributes
 * (`created_at`, `updated_at`, `created_by`, `updated_by`).
 *
 * Mirrors {@see BuiltInObjectTypeSeeder} — the migration handles every
 * tenant that exists at deploy time; this service handles tenants created
 * after that. Idempotent: re-runs are a no-op once the rows exist.
 *
 * Visibility is explicit modeling configuration: these attributes collect
 * values from the beginning, but they render on forms only after the user
 * attaches them to an ObjectType either loose or through any AttributeGroup.
 */
final readonly class BuiltInSystemAttributesSeeder
{
    /**
     * @var array<string, array{AttributeType, array<string, string>, array<string, mixed>, int}>
     */
    private const array ATTRIBUTES = [
        'created_at' => [
            AttributeType::Datetime,
            ['pl' => 'Utworzono', 'en' => 'Created at'],
            [],
            1,
        ],
        'updated_at' => [
            AttributeType::Datetime,
            ['pl' => 'Zmieniono', 'en' => 'Updated at'],
            [],
            2,
        ],
        'created_by' => [
            AttributeType::Reference,
            ['pl' => 'Utworzony przez', 'en' => 'Created by'],
            ['target_entity' => 'user'],
            3,
        ],
        'updated_by' => [
            AttributeType::Reference,
            ['pl' => 'Zmieniony przez', 'en' => 'Updated by'],
            ['target_entity' => 'user'],
            4,
        ],
    ];

    public function __construct(
        private AttributeRepositoryInterface $attributeRepository,
        private EntityManagerInterface $em,
        private TenantContext $tenantContext,
    ) {
    }

    /**
     * Seed missing system attributes for the given tenant. Returns the number
     * of attributes actually created.
     */
    public function seed(Tenant $tenant): int
    {
        $previous = $this->tenantContext->get();
        $this->tenantContext->set($tenant);

        try {
            $created = 0;
            foreach (self::ATTRIBUTES as $code => [$type, $label, $rules, $position]) {
                $existing = $this->attributeRepository->findByCode($code, $tenant);
                if (null !== $existing) {
                    continue;
                }

                $attribute = new Attribute($code, $label, $type);
                $attribute->markSystem();
                $attribute->reorder($position);
                if ([] !== $rules) {
                    $attribute->updateValidationRules($rules);
                }
                $this->em->persist($attribute);
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
