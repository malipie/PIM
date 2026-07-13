<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Contracts\Query\ObjectSample;
use App\Catalog\Contracts\Query\ObjectSamplePort;
use App\Catalog\Domain\Entity\CatalogObject;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * #2550 — {@see ObjectSamplePort} implementation. Applies the profile's
 * canonical scope (ObjectType list + status/enabled/completeness) as a bounded
 * DQL query and returns the denormalised rows. Tenant isolation comes from the
 * Doctrine TenantFilter + RLS, so no explicit tenant clause is needed. Mirrors
 * the axes {@see \App\Catalog\Infrastructure\ApiPlatform\State\ProfileScopeApplier}
 * applies on the live API so the preview matches what an integrator receives.
 */
final readonly class ObjectSampleReader implements ObjectSamplePort
{
    private const int MAX_LIMIT = 50;

    /** @var array<string, string> */
    private const array COMPLETENESS_OPERATORS = [
        'eq' => '=',
        'gt' => '>',
        'gte' => '>=',
        'lt' => '<',
        'lte' => '<=',
    ];

    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function sample(array $objectTypeIds, array $canonicalFilters, int $limit): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('o')
            ->from(CatalogObject::class, 'o')
            ->setMaxResults(max(1, min($limit, self::MAX_LIMIT)));

        $validTypeIds = array_values(array_filter(
            $objectTypeIds,
            static fn (string $id): bool => Uuid::isValid($id),
        ));
        if ([] !== $validTypeIds) {
            $qb->andWhere('IDENTITY(o.objectType) IN (:otids)')->setParameter('otids', $validTypeIds);
        }

        $status = $canonicalFilters['status'] ?? null;
        if (\is_string($status) && '' !== $status) {
            $qb->andWhere('o.status = :status')->setParameter('status', $status);
        }

        $enabled = match ($canonicalFilters['enabled'] ?? null) {
            true, 'true', '1', 1 => true,
            false, 'false', '0', 0 => false,
            default => null,
        };
        if (null !== $enabled) {
            $qb->andWhere('o.enabled = :enabled')->setParameter('enabled', $enabled);
        }

        $completeness = $canonicalFilters['completeness'] ?? null;
        if (\is_array($completeness)) {
            foreach ($completeness as $op => $threshold) {
                $sqlOperator = self::COMPLETENESS_OPERATORS[$op] ?? null;
                if (null === $sqlOperator || !is_numeric($threshold)) {
                    continue;
                }
                $qb->andWhere(\sprintf('o.completenessPct %s :cmp_%s', $sqlOperator, $op))
                    ->setParameter('cmp_'.$op, (int) $threshold);
            }
        }

        $rows = [];
        /** @var CatalogObject $object */
        foreach ($qb->getQuery()->getResult() as $object) {
            $rows[] = new ObjectSample(
                $object->getId()->toRfc4122(),
                $object->getCode(),
                $object->getKind()->value,
                $object->getAttributesIndexed(),
            );
        }

        return $rows;
    }
}
