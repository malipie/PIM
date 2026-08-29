<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Repository;

use App\Catalog\Domain\Entity\AttributeGroup;
use App\Shared\Domain\Tenant;
use Symfony\Component\Uid\Uuid;

interface AttributeGroupRepositoryInterface
{
    public function findById(Uuid $id): ?AttributeGroup;

    public function findByCode(string $code, Tenant $tenant): ?AttributeGroup;

    /**
     * @param list<string> $codes
     *
     * @return array<string, AttributeGroup> code => group
     */
    public function findByCodes(array $codes, Tenant $tenant): array;

    /**
     * All groups owned by the tenant, ordered for deterministic export
     * (position, then code).
     *
     * @return list<AttributeGroup>
     */
    public function findAllByTenant(Tenant $tenant): array;

    public function save(AttributeGroup $attributeGroup): void;

    public function remove(AttributeGroup $attributeGroup): void;
}
