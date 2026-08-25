<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Contracts\Query\AttributeGroupSummary;
use App\Catalog\Contracts\Service\AttributeGroupCatalogReader;
use App\Catalog\Domain\Repository\AttributeGroupRepositoryInterface;
use App\Shared\Domain\Repository\TenantRepositoryInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineAttributeGroupCatalogReader implements AttributeGroupCatalogReader
{
    public function __construct(
        private AttributeGroupRepositoryInterface $groups,
        private TenantRepositoryInterface $tenants,
    ) {
    }

    public function findAllByTenant(Uuid $tenantId): array
    {
        $tenant = $this->tenants->findById($tenantId);
        if (null === $tenant) {
            return [];
        }

        return array_map(
            static fn ($group): AttributeGroupSummary => new AttributeGroupSummary(
                id: $group->getId(),
                tenantId: $tenantId,
                code: $group->getCode(),
                label: $group->getLabel(),
            ),
            $this->groups->findAllByTenant($tenant),
        );
    }
}
