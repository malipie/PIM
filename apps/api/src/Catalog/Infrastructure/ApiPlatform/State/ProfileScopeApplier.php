<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ApiPlatform\State;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * #2527 — applies the canonical ApiProfile row-scope to a CatalogObject
 * query. Shared by {@see ProfileScopeCollectionExtension} and
 * {@see ProfileScopeItemExtension} so a partner's profile narrows both
 * `GET /api/products` and `GET /api/products/{id}` identically.
 *
 * API Platform snapshots `$context['filters']` before
 * {@see \App\ApiConfigurator\Infrastructure\ApiPlatform\ApiProfileFilterRequestListener}
 * runs, so the existing FilterInterface chain never sees the merged
 * profile params. This reads the live request query instead (mirroring
 * AssetCollectionFilterExtension) and applies the column-backed canonical
 * scopes as DQL: `status`, `enabled`, `completeness` and `objectTypeIds`.
 * Attribute-value profile filters on the live API are a follow-up.
 *
 * Guarded on the `_pim_profile_scoped` request attribute the listener sets,
 * so admin/JWT requests (no profile) are never scoped.
 */
final class ProfileScopeApplier
{
    /** Marks a request whose query carries a resolved profile's scope. */
    public const string REQUEST_ATTRIBUTE = '_pim_profile_scoped';

    /** @var array<string, string> */
    private const array COMPLETENESS_OPERATORS = [
        'eq' => '=',
        'gt' => '>',
        'gte' => '>=',
        'lt' => '<',
        'lte' => '<=',
    ];

    public function apply(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        Request $request,
    ): void {
        if (!$request->attributes->getBoolean(self::REQUEST_ATTRIBUTE)) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0] ?? null;
        if (null === $alias) {
            return;
        }

        $query = $request->query;

        $status = $query->get('status');
        if (null !== $status && '' !== $status) {
            $parameter = $queryNameGenerator->generateParameterName('scopeStatus');
            $queryBuilder
                ->andWhere(\sprintf('%s.status = :%s', $alias, $parameter))
                ->setParameter($parameter, $status);
        }

        $enabled = match ($query->get('enabled')) {
            'true', '1' => true,
            'false', '0' => false,
            default => null,
        };
        if (null !== $enabled) {
            $parameter = $queryNameGenerator->generateParameterName('scopeEnabled');
            $queryBuilder
                ->andWhere(\sprintf('%s.enabled = :%s', $alias, $parameter))
                ->setParameter($parameter, $enabled);
        }

        foreach ($query->all('completeness') as $op => $threshold) {
            $sqlOperator = self::COMPLETENESS_OPERATORS[$op] ?? null;
            if (null === $sqlOperator || !is_numeric($threshold)) {
                continue;
            }
            $parameter = $queryNameGenerator->generateParameterName('scopeCompleteness_'.$op);
            $queryBuilder
                ->andWhere(\sprintf('%s.completenessPct %s :%s', $alias, $sqlOperator, $parameter))
                ->setParameter($parameter, (int) $threshold);
        }

        $ids = [];
        foreach ($query->all('objectTypeIds') as $value) {
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
