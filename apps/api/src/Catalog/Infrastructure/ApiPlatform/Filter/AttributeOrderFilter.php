<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ApiPlatform\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Identity\Contracts\Policy\AttributePermissionReader;
use App\Shared\Application\TenantContext;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * GRID-P5-02 (#2398) — `?order[attribute.{code}]={asc|desc}`: sorts the
 * object list by an attribute reading from `attributes_indexed`.
 *
 * ADR-0028 rules, enforced here independently of the list-schema
 * `sortable` flag:
 *  - simple, non-localizable, non-scopable types only;
 *  - envelope path per type (`value` / `option_code` / price `amount`),
 *    numeric cast for number/metric/price, text otherwise (ISO dates
 *    sort lexicographically = chronologically);
 *  - `NULLS LAST` via a hidden CASE rank (DQL has no native syntax);
 *  - deterministic tie-breaker `id DESC`;
 *  - one attribute sort at a time (MVP);
 *  - unknown / unsortable / RBAC-restricted codes all fail with the
 *    SAME 400 so a restricted attribute's existence never leaks.
 *
 * Sorted requests paginate LIMIT/OFFSET (`page`/`itemsPerPage`) — the
 * id-cursor cannot encode a position in an attribute ordering.
 */
final class AttributeOrderFilter implements FilterInterface
{
    private const string PARAMETER = 'order';
    private const string PREFIX = 'attribute.';

    private const array SORTABLE_TYPES = [
        AttributeType::Text,
        AttributeType::Textarea,
        AttributeType::Identifier,
        AttributeType::Number,
        AttributeType::Metric,
        AttributeType::Date,
        AttributeType::Datetime,
        AttributeType::Boolean,
        AttributeType::Select,
        AttributeType::Price,
        AttributeType::Email,
        AttributeType::Color,
    ];

    private const array NUMERIC_TYPES = [
        AttributeType::Number,
        AttributeType::Metric,
        AttributeType::Price,
    ];

    public function __construct(
        private readonly AttributeRepositoryInterface $attributes,
        private readonly AttributePermissionReader $attributePermissions,
        private readonly TenantContext $tenantContext,
    ) {
    }

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
        $order = $filters[self::PARAMETER] ?? null;
        if (!\is_array($order)) {
            return;
        }

        $attributeSorts = [];
        foreach ($order as $property => $direction) {
            if (\is_string($property) && str_starts_with($property, self::PREFIX)) {
                $attributeSorts[substr($property, \strlen(self::PREFIX))] = $direction;
            }
        }
        if ([] === $attributeSorts) {
            return;
        }
        if (\count($attributeSorts) > 1) {
            throw new BadRequestHttpException('Only one attribute sort is supported.');
        }

        $code = array_key_first($attributeSorts);
        $direction = strtolower((string) $attributeSorts[$code]);
        if (!\in_array($direction, ['asc', 'desc'], true)) {
            throw new BadRequestHttpException(\sprintf('Invalid sort direction for attribute "%s".', $code));
        }

        $attribute = $this->resolveSortable($code);

        $alias = $queryBuilder->getRootAliases()[0] ?? null;
        if (null === $alias) {
            return;
        }

        $field = AttributeType::Price === $attribute->getType() ? 'amount'
            : ($attribute->getType()->usesOptions() ? 'option_code' : 'value');
        $function = \in_array($attribute->getType(), self::NUMERIC_TYPES, true)
            ? 'JSONB_PATH_NUMERIC'
            : 'JSONB_PATH_TEXT';

        $pathParam = $queryNameGenerator->generateParameterName('attr_sort_path');
        $expression = \sprintf('%s(%s.attributesIndexed, :%s)', $function, $alias, $pathParam);

        // DQL has no NULLS LAST — a hidden CASE rank keeps missing
        // readings at the tail for both directions.
        $queryBuilder
            ->addSelect(\sprintf('CASE WHEN %s IS NULL THEN 1 ELSE 0 END AS HIDDEN attr_sort_nulls', $expression))
            ->addSelect(\sprintf('%s AS HIDDEN attr_sort_value', $expression))
            ->setParameter($pathParam, \sprintf('{%s,%s}', $code, $field))
            ->addOrderBy('attr_sort_nulls', 'ASC')
            ->addOrderBy('attr_sort_value', $direction)
            ->addOrderBy(\sprintf('%s.id', $alias), 'DESC');
    }

    private function resolveSortable(string $code): Attribute
    {
        // One uniform 400 for unknown / unsortable / restricted — the
        // response must not reveal whether a restricted attribute exists.
        $rejection = new BadRequestHttpException(\sprintf('Attribute "%s" is not sortable.', $code));

        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw $rejection;
        }
        $attribute = $this->attributes->findByCode($code, $tenant);
        if (null === $attribute) {
            throw $rejection;
        }
        if (
            !\in_array($attribute->getType(), self::SORTABLE_TYPES, true)
            || $attribute->isLocalizable()
            || $attribute->isScopable()
            || !$this->attributePermissions->canViewAttribute($attribute->getId())
        ) {
            throw $rejection;
        }

        return $attribute;
    }

    /**
     * @param class-string $resourceClass
     *
     * @return array<string, array{property?: string, type?: string, required?: bool, description?: string}>
     */
    public function getDescription(string $resourceClass): array
    {
        return [
            self::PARAMETER.'[attribute.:code]' => [
                'property' => 'attributesIndexed',
                'type' => 'string',
                'required' => false,
                'description' => 'Sort by an attribute reading (asc|desc). Simple non-localizable types only; NULLS LAST; tie-broken by id DESC. Sorted requests paginate via page/itemsPerPage.',
            ],
        ];
    }
}
