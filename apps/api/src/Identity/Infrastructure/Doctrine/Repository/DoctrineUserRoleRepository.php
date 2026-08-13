<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine\Repository;

use App\Identity\Domain\Entity\UserRole;
use App\Identity\Domain\Repository\UserRoleRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<UserRole>
 */
class DoctrineUserRoleRepository extends ServiceEntityRepository implements UserRoleRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserRole::class);
    }

    public function findById(Uuid $id): ?UserRole
    {
        return parent::find($id->toRfc4122());
    }

    /**
     * @return list<UserRole>
     */
    public function findByUser(Uuid $userId): array
    {
        // ADR-0034 — `user`/`role` are associations now; Doctrine accepts the
        // identifier as the criteria value, so callers keep passing UUIDs.
        return $this->findBy(['user' => $userId->toRfc4122()]);
    }

    /**
     * @return list<UserRole>
     */
    public function findByRole(Uuid $roleId): array
    {
        return $this->findBy(['role' => $roleId->toRfc4122()]);
    }

    public function findByUserAndRole(Uuid $userId, Uuid $roleId): ?UserRole
    {
        return $this->findOneBy([
            'user' => $userId->toRfc4122(),
            'role' => $roleId->toRfc4122(),
        ]);
    }

    public function save(UserRole $entity): void
    {
        $em = $this->getEntityManager();
        $em->persist($entity);
        $em->flush();
    }

    public function remove(UserRole $entity): void
    {
        $em = $this->getEntityManager();
        $em->remove($entity);
        $em->flush();
    }
}
