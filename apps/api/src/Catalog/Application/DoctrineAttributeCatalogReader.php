<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Contracts\Query\AttributeSummary;
use App\Catalog\Contracts\Service\AttributeCatalogReader;
use App\Catalog\Domain\Repository\AttributeOptionRepositoryInterface;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Shared\Domain\Repository\TenantRepositoryInterface;
use Symfony\Component\Uid\Uuid;

use const PHP_INT_MAX;

/**
 * RBAC-P5-007 (#697) — adapter that turns Catalog domain attributes
 * into {@see AttributeSummary} DTOs for cross-BC consumption.
 *
 * Lives in Application (not Domain) because it depends on a Shared
 * `TenantRepositoryInterface` to look up the Tenant entity needed by
 * the underlying repository. Callers pass a tenant id; the adapter
 * resolves it once per request.
 */
final readonly class DoctrineAttributeCatalogReader implements AttributeCatalogReader
{
    public function __construct(
        private AttributeRepositoryInterface $attributes,
        private AttributeOptionRepositoryInterface $options,
        private TenantRepositoryInterface $tenants,
    ) {
    }

    public function findAllByTenant(Uuid $tenantId): array
    {
        $tenant = $this->tenants->findById($tenantId);
        if (null === $tenant) {
            return [];
        }

        $summaries = [];
        foreach ($this->attributes->findAllByTenant($tenant) as $attribute) {
            $group = $attribute->getGroup();
            $summaries[] = new AttributeSummary(
                id: $attribute->getId(),
                tenantId: $tenantId,
                code: $attribute->getCode(),
                label: $attribute->getLabel(),
                type: $attribute->getType()->value,
                isLocalizable: $attribute->isLocalizable(),
                isRequired: $attribute->isRequired(),
                isSystem: $attribute->isSystem(),
                groupId: $group?->getId(),
                groupCode: $group?->getCode(),
                groupLabel: null === $group ? [] : $group->getLabel(),
                groupPosition: $group?->getPosition() ?? PHP_INT_MAX,
            );
        }

        return $summaries;
    }

    public function findOnTenant(Uuid $attributeId, Uuid $tenantId): ?AttributeSummary
    {
        $attribute = $this->attributes->findById($attributeId);
        if (null === $attribute) {
            return null;
        }
        $attrTenant = $attribute->getTenant();
        if (null === $attrTenant || !$tenantId->equals($attrTenant->getId())) {
            return null;
        }

        $group = $attribute->getGroup();

        return new AttributeSummary(
            id: $attribute->getId(),
            tenantId: $tenantId,
            code: $attribute->getCode(),
            label: $attribute->getLabel(),
            type: $attribute->getType()->value,
            isLocalizable: $attribute->isLocalizable(),
            isRequired: $attribute->isRequired(),
            isSystem: $attribute->isSystem(),
            groupId: $group?->getId(),
            groupCode: $group?->getCode(),
            groupLabel: null === $group ? [] : $group->getLabel(),
            groupPosition: $group?->getPosition() ?? PHP_INT_MAX,
        );
    }

    public function optionsFor(Uuid $attributeId, Uuid $tenantId): array
    {
        $summary = $this->findOnTenant($attributeId, $tenantId);
        if (null === $summary) {
            return [];
        }

        $attribute = $this->attributes->findById($attributeId);
        if (null === $attribute) {
            return [];
        }

        $options = [];
        foreach ($this->options->findByAttribute($attribute) as $option) {
            $options[] = ['code' => $option->getCode(), 'label' => $option->getLabel()];
        }

        return $options;
    }
}
