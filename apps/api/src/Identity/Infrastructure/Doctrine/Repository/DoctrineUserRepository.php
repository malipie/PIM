<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine\Repository;

use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<User>
 */
class DoctrineUserRepository extends ServiceEntityRepository implements UserRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => $email]);
    }

    public function findById(Uuid $id): ?User
    {
        return parent::find($id->toRfc4122());
    }

    public function save(User $entity): void
    {
        $em = $this->getEntityManager();
        $em->persist($entity);
        $em->flush();
    }

    public function remove(User $entity): void
    {
        $em = $this->getEntityManager();
        $em->remove($entity);
        $em->flush();
    }

    public function findAllByTenantPaginated(
        Tenant $tenant,
        ?string $status,
        ?array $roleIds,
        ?string $search,
        int $page,
        int $perPage,
    ): array {
        $qb = $this->buildBaseQuery($tenant, $status, $roleIds, $search)
            ->orderBy('u.email', 'ASC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults(max(1, $perPage));

        /** @var list<User> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    public function countByTenant(
        Tenant $tenant,
        ?string $status,
        ?array $roleIds,
        ?string $search,
    ): int {
        $qb = $this->buildBaseQuery($tenant, $status, $roleIds, $search)
            ->select('COUNT(DISTINCT u.id)');

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function countAssignedToRole(Uuid $roleId): int
    {
        // ADR-0034 — role grants hang off the assignment entity now.
        $qb = $this->createQueryBuilder('u')
            ->select('COUNT(DISTINCT u.id)')
            ->innerJoin('u.roleAssignments', 'ura')
            ->innerJoin('ura.role', 'r')
            ->where('r.id = :roleId')
            ->setParameter('roleId', $roleId);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @param list<string>|null $roleIds
     */
    private function buildBaseQuery(
        Tenant $tenant,
        ?string $status,
        ?array $roleIds,
        ?string $search,
    ): QueryBuilder {
        $qb = $this->createQueryBuilder('u')
            ->where('u.tenant = :tenant')
            ->setParameter('tenant', $tenant->getId());

        if (null !== $status) {
            $qb->andWhere('u.status = :status')->setParameter('status', $status);
        }

        if (null !== $search && '' !== $search) {
            $qb->andWhere('LOWER(u.email) LIKE :search')
                ->setParameter('search', '%'.strtolower($search).'%');
        }

        if (null !== $roleIds && [] !== $roleIds) {
            // Intersection with the role assignments via EXISTS — a plain
            // INNER JOIN + DISTINCT trips Postgres' "no equality operator for
            // type json" error because Doctrine projects the legacy
            // User.roles json column on the select clause.
            $sub = $this->createQueryBuilder('u_sub')
                ->select('u_sub.id')
                ->innerJoin('u_sub.roleAssignments', 'ura_sub')
                ->innerJoin('ura_sub.role', 'r')
                ->where('u_sub.id = u.id')
                ->andWhere('r.id IN (:roleIds)');
            $qb->andWhere($qb->expr()->exists($sub->getDQL()))
                ->setParameter('roleIds', $roleIds);
        }

        return $qb;
    }
}
