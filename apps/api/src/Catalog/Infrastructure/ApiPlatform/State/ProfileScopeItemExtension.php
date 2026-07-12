<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ApiPlatform\State;

use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Catalog\Domain\Entity\CatalogObject;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * #2527 — item-query counterpart to the profile row-scope applied on
 * collections by {@see \App\ApiConfigurator\Infrastructure\ApiPlatform\ApiProfileFilterRequestListener}
 * + the Catalog filter chain.
 *
 * API Platform runs FilterInterface only on collections, so without this a
 * partner scoped to `status=published` could still fetch a draft by its id
 * via `GET /api/products/{id}`. The listener injects the profile's canonical
 * scope (`status`, `objectTypeIds`) into the request query; this extension
 * mirrors it on the item query, so an out-of-scope object 404s (same shape
 * as {@see KindItemExtension}). Reads the query params only — no dependency
 * on the ApiConfigurator BC.
 *
 * v1 enforces the security-relevant canonical scopes (`status`,
 * `objectTypeIds`); completeness / attribute item-scoping is a follow-up.
 */
final readonly class ProfileScopeItemExtension implements QueryItemExtensionInterface
{
    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @param class-string         $resourceClass
     * @param array<string, mixed> $identifiers
     */
    public function applyToItem(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        array $identifiers,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        if (CatalogObject::class !== $resourceClass) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0] ?? null;
        if (null === $alias) {
            return;
        }

        $status = $request->query->get('status');
        if (\is_string($status) && '' !== $status) {
            $parameter = $queryNameGenerator->generateParameterName('scopeStatus');
            $queryBuilder
                ->andWhere(\sprintf('%s.status = :%s', $alias, $parameter))
                ->setParameter($parameter, $status);
        }

        $objectTypeIds = $request->query->all('objectTypeIds');
        $ids = [];
        foreach ($objectTypeIds as $value) {
            if (\is_string($value) && Uuid::isValid($value)) {
                $ids[] = $value;
            }
        }
        if ([] !== $ids) {
            $parameter = $queryNameGenerator->generateParameterName('scopeObjectTypeIds');
            $queryBuilder
                ->andWhere(\sprintf('IDENTITY(%s.objectType) IN (:%s)', $alias, $parameter))
                ->setParameter($parameter, $ids);
        }
    }
}
