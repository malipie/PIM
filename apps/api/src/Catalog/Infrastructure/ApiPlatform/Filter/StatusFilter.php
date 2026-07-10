<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ApiPlatform\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Catalog\Domain\Entity\CatalogObject;
use Doctrine\ORM\QueryBuilder;

/**
 * `?status=draft|review|published|archived` plus `?enabled=true|false`.
 *
 * Two related but distinct flags on `CatalogObject`:
 *   - `status` is the editorial state (`object_editorial` state
 *     machine, ADR-0029: draft → review → published → archived). String exact match.
 *   - `enabled` is the publishing kill-switch independent of status —
 *     a published row can be disabled to hide it everywhere without
 *     losing its archive history. Boolean.
 *
 * Both are common admin-list filters, kept in one filter class because
 * the DQL is identical and the URL surface stays compact.
 */
final class StatusFilter implements FilterInterface
{
    private const string STATUS_PARAMETER = 'status';
    private const string ENABLED_PARAMETER = 'enabled';

    /**
     * @var list<string>
     */
    private const array ALLOWED_STATUSES = [
        CatalogObject::STATUS_DRAFT,
        CatalogObject::STATUS_REVIEW,
        CatalogObject::STATUS_PUBLISHED,
        CatalogObject::STATUS_ARCHIVED,
    ];

    public function apply(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $alias = $queryBuilder->getRootAliases()[0] ?? null;
        if (null === $alias) {
            return;
        }

        $filters = $context['filters'] ?? [];
        if (!\is_array($filters)) {
            return;
        }

        $statusValue = $filters[self::STATUS_PARAMETER] ?? null;
        if (\is_string($statusValue) && \in_array($statusValue, self::ALLOWED_STATUSES, true)) {
            $statusParam = $queryNameGenerator->generateParameterName('status');
            $queryBuilder
                ->andWhere(\sprintf('%s.status = :%s', $alias, $statusParam))
                ->setParameter($statusParam, $statusValue);
        }

        $enabledValue = $filters[self::ENABLED_PARAMETER] ?? null;
        $enabledBool = match ($enabledValue) {
            true, 'true', '1', 1 => true,
            false, 'false', '0', 0 => false,
            default => null,
        };
        if (null !== $enabledBool) {
            $enabledParam = $queryNameGenerator->generateParameterName('enabled');
            $queryBuilder
                ->andWhere(\sprintf('%s.enabled = :%s', $alias, $enabledParam))
                ->setParameter($enabledParam, $enabledBool);
        }
    }

    /**
     * @param class-string $resourceClass
     *
     * @return array<string, array{property?: string, type?: string, required?: bool, description?: string, strategy?: string, is_collection?: bool}>
     */
    public function getDescription(string $resourceClass): array
    {
        return [
            self::STATUS_PARAMETER => [
                'property' => 'status',
                'type' => 'string',
                'required' => false,
                'description' => 'Editorial state: draft, published, archived.',
                'strategy' => 'exact',
            ],
            self::ENABLED_PARAMETER => [
                'property' => 'enabled',
                'type' => 'bool',
                'required' => false,
                'description' => 'Publishing kill-switch flag.',
                'strategy' => 'exact',
            ],
        ];
    }
}
