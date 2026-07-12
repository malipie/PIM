<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ApiPlatform\State;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Catalog\Domain\Entity\CatalogObject;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * #2527 — narrows `GET /api/products` (and the other CatalogObject sugar
 * paths) to the resolved ApiProfile's row-scope on the live API.
 *
 * The profile params are injected into the request query by
 * {@see \App\ApiConfigurator\Infrastructure\ApiPlatform\ApiProfileFilterRequestListener};
 * because API Platform snapshots `$context['filters']` before that
 * listener runs, this extension reads the live query via {@see RequestStack}
 * and applies the canonical scope through {@see ProfileScopeApplier}
 * (the same applier used on item queries — collection + item stay in sync).
 */
final readonly class ProfileScopeCollectionExtension implements QueryCollectionExtensionInterface
{
    public function __construct(
        private RequestStack $requestStack,
        private ProfileScopeApplier $applier,
    ) {
    }

    /**
     * @param class-string $resourceClass
     */
    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
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

        $this->applier->apply($queryBuilder, $queryNameGenerator, $request);
    }
}
