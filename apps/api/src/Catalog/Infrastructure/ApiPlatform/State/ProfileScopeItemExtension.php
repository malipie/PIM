<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ApiPlatform\State;

use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Catalog\Domain\Entity\CatalogObject;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * #2527 — item-query counterpart to {@see ProfileScopeCollectionExtension}.
 *
 * API Platform runs FilterInterface only on collections, so without this a
 * partner scoped to `status=published` could still fetch a draft by its id
 * via `GET /api/products/{id}`. Applies the same profile row-scope via the
 * shared {@see ProfileScopeApplier}, so an out-of-scope object 404s (same
 * shape as {@see KindItemExtension}). Reads the query only — no dependency
 * on the ApiConfigurator BC.
 */
final readonly class ProfileScopeItemExtension implements QueryItemExtensionInterface
{
    public function __construct(
        private RequestStack $requestStack,
        private ProfileScopeApplier $applier,
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

        $this->applier->apply($queryBuilder, $queryNameGenerator, $request);
    }
}
