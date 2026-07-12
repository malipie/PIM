<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ApiPlatform\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Uuid;

/**
 * #2527 — `?objectTypeIds[]=<uuid>&objectTypeIds[]=<uuid>` narrows the
 * collection to instances of a SET of ObjectTypes (plural of
 * {@see ObjectTypeFilter}, which takes a single UUID).
 *
 * The live API scopes a partner integration to the ObjectTypes its
 * {@see \App\ApiConfigurator\Domain\Entity\ApiProfile} allows: the
 * {@see \App\ApiConfigurator\Infrastructure\ApiPlatform\ApiProfileFilterRequestListener}
 * injects the profile's `objectTypeIds` into the request query, and this
 * filter applies them as `IN`. Communication is via query params only, so
 * the Catalog filter never depends on the ApiConfigurator BC.
 *
 * Invalid / non-UUID entries are skipped; an empty (or all-invalid) list is
 * a no-op. Tenant scoping still applies via the Doctrine TenantFilter.
 */
final class ObjectTypeIdsFilter implements FilterInterface
{
    private const string PARAMETER = 'objectTypeIds';

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

        $raw = $filters[self::PARAMETER] ?? null;
        if (!\is_array($raw)) {
            return;
        }

        $ids = [];
        foreach ($raw as $value) {
            if (\is_string($value) && Uuid::isValid($value)) {
                $ids[] = $value;
            }
        }
        if ([] === $ids) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0] ?? null;
        if (null === $alias) {
            return;
        }

        $parameter = $queryNameGenerator->generateParameterName('objectTypeIds');
        $queryBuilder
            ->andWhere(\sprintf('IDENTITY(%s.objectType) IN (:%s)', $alias, $parameter))
            ->setParameter($parameter, $ids);
    }

    /**
     * @param class-string $resourceClass
     *
     * @return array<string, array{property?: string, type?: string, required?: bool, description?: string, strategy?: string, is_collection?: bool}>
     */
    public function getDescription(string $resourceClass): array
    {
        return [
            self::PARAMETER.'[]' => [
                'property' => 'objectType',
                'type' => 'string',
                'required' => false,
                'description' => 'Scope the collection to a set of ObjectTypes by UUID. Applied as IN; drives per-profile ObjectType scoping on the live API.',
                'strategy' => 'exact',
                'is_collection' => true,
            ],
        ];
    }
}
