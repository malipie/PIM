<?php

declare(strict_types=1);

namespace App\DataFixtures\Demo;

use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeGroup;
use App\Catalog\Domain\Entity\AttributeGroupAttribute;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\Entity\ObjectTypeAttributeGroup;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;

use const ARRAY_FILTER_USE_KEY;

/**
 * Modeling phase of the electronics demo seed: AttributeGroups, their
 * memberships and the ObjectType attachments / attribute junctions.
 * Idempotent — every insert is preceded by a lookup, so re-runs (and the
 * `--keep-existing` mode) are no-ops. Split out of
 * {@see ElectronicsDemoSeeder} so the seeder stays under the API
 * max-lines guard.
 */
final readonly class ElectronicsDemoModelingSeeder
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<string, Attribute> $attributes
     *
     * @return array<string, AttributeGroup>
     */
    public function ensureGroups(Tenant $tenant, array $attributes, ObjectType $productType, ObjectType $serviceType): array
    {
        $groupRepo = $this->em->getRepository(AttributeGroup::class);
        $memberRepo = $this->em->getRepository(AttributeGroupAttribute::class);
        $attachRepo = $this->em->getRepository(ObjectTypeAttributeGroup::class);

        $groups = [];
        $position = 0;
        foreach (ElectronicsDemoData::groupDefinitions() as $code => $def) {
            $group = $groupRepo->findOneBy(['code' => $code, 'tenant' => $tenant]);
            if (null === $group) {
                $group = new AttributeGroup(
                    code: $code,
                    label: $def['label'],
                    position: $position,
                    icon: $def['icon'],
                    color: $def['color'],
                    isRequiredSection: $def['required_section'] ?? false,
                );
                $this->em->persist($group);
            }
            $groups[$code] = $group;

            foreach ($def['attributes'] as $memberPosition => $attributeCode) {
                $member = $memberRepo->findOneBy(['attributeGroup' => $group, 'attribute' => $attributes[$attributeCode]]);
                if (null === $member) {
                    $this->em->persist(new AttributeGroupAttribute($group, $attributes[$attributeCode], $memberPosition));
                }
            }
            ++$position;
        }
        $this->em->flush();

        // Base groups always visible on every product regardless of category.
        foreach (['basic', 'pricing', 'media', 'logistics', 'marketing'] as $attachPosition => $code) {
            $existing = $attachRepo->findOneBy(['objectType' => $productType, 'attributeGroup' => $groups[$code]]);
            if (null === $existing) {
                $this->em->persist(new ObjectTypeAttributeGroup($productType, $groups[$code], $attachPosition));
            }
        }
        foreach (['service_basic', 'service_params'] as $attachPosition => $code) {
            $existing = $attachRepo->findOneBy(['objectType' => $serviceType, 'attributeGroup' => $groups[$code]]);
            if (null === $existing) {
                $this->em->persist(new ObjectTypeAttributeGroup($serviceType, $groups[$code], $attachPosition));
            }
        }

        return $groups;
    }

    /**
     * Wire attribute junctions (completeness + list columns) for both
     * ObjectTypes, skipping codes the fixtures already attached.
     *
     * @param array<string, Attribute> $attributes
     */
    public function ensureJunctions(ObjectType $productType, ObjectType $serviceType, array $attributes): void
    {
        $junctionRepo = $this->em->getRepository(ObjectTypeAttribute::class);

        $productCodes = array_unique(array_merge(
            ...array_values(array_map(
                static fn (array $def): array => $def['attributes'],
                array_filter(ElectronicsDemoData::groupDefinitions(), static fn (string $code): bool => !str_starts_with($code, 'service_'), ARRAY_FILTER_USE_KEY),
            )),
        ));
        $serviceCodes = array_unique(array_merge(
            ElectronicsDemoData::groupDefinitions()['service_basic']['attributes'],
            ElectronicsDemoData::groupDefinitions()['service_params']['attributes'],
        ));

        foreach ([[$productType, $productCodes], [$serviceType, $serviceCodes]] as [$type, $codes]) {
            $sort = 100;
            foreach ($codes as $code) {
                $existing = $junctionRepo->findOneBy(['objectType' => $type, 'attribute' => $attributes[$code]]);
                if (null !== $existing) {
                    continue;
                }
                $required = \in_array($code, ['sku', 'name', 'description', 'price'], true);
                $this->em->persist(new ObjectTypeAttribute($type, $attributes[$code], $required, $sort++));
            }
        }
    }
}
