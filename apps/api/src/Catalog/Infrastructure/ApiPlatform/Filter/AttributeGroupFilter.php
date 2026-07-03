<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ApiPlatform\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Catalog\Domain\Entity\AttributeGroupAttribute;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Uuid;

/**
 * DP-04 (#2034) — `?attributeGroup=<uuid>` scopes `/api/attributes` to
 * attributes assigned to a specific AttributeGroup.
 *
 * Membership is dual-path during the UI-08 transition (ADR-012): the
 * authoritative M:N `attribute_group_attributes` junction OR the legacy
 * `attributes.group_id` FK (ADR-009) still written by pre-migration data.
 * The filter ORs both so pre-junction rows keep showing up until the data
 * migration lands.
 *
 * Empty / non-UUID values are skipped — a stale FE param must not blow up
 * the collection. Tenant scoping still applies via
 * {@see \App\Shared\Infrastructure\Doctrine\Filter\TenantFilter}.
 */
final class AttributeGroupFilter implements FilterInterface
{
    private const string PARAMETER = 'attributeGroup';

    public function apply(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $filters = $context['filters'] ?? [];
        if (!\is_array($filters)) {
            return;
        }

        $value = $filters[self::PARAMETER] ?? null;
        if (!\is_string($value) || !Uuid::isValid($value)) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0] ?? null;
        if (null === $alias) {
            return;
        }

        $parameter = $queryNameGenerator->generateParameterName('attributeGroupId');
        $junctionAlias = $queryNameGenerator->generateJoinAlias('aga');

        $queryBuilder
            ->andWhere(\sprintf(
                'EXISTS (SELECT 1 FROM %s %s WHERE IDENTITY(%s.attribute) = %s.id AND IDENTITY(%s.attributeGroup) = :%s) OR IDENTITY(%s.group) = :%s',
                AttributeGroupAttribute::class,
                $junctionAlias,
                $junctionAlias,
                $alias,
                $junctionAlias,
                $parameter,
                $alias,
                $parameter,
            ))
            ->setParameter($parameter, $value);
    }

    /**
     * @param class-string $resourceClass
     *
     * @return array<string, array{property?: string, type?: string, required?: bool, description?: string, strategy?: string, is_collection?: bool}>
     */
    public function getDescription(string $resourceClass): array
    {
        return [
            self::PARAMETER => [
                'property' => 'group',
                'type' => 'string',
                'required' => false,
                'description' => 'Scope the collection to attributes assigned to the given AttributeGroup (UUID). Matches both the attribute_group_attributes junction (ADR-012) and the legacy attributes.group_id FK (ADR-009).',
                'strategy' => 'exact',
            ],
        ];
    }
}
