<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine\Repository;

use App\Identity\Domain\Entity\Role;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Rbac\RbacMatrix;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Shared\Domain\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Role>
 */
class DoctrineRoleRepository extends ServiceEntityRepository implements RoleRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Role::class);
    }

    public function findGlobalByCode(string $code): ?Role
    {
        return $this->findOneBy(['code' => $code, 'tenant' => null]);
    }

    public function findByCode(string $code, ?Tenant $tenant = null): ?Role
    {
        return $this->findOneBy(['code' => $code, 'tenant' => $tenant]);
    }

    public function findById(Uuid $id): ?Role
    {
        return parent::find($id->toRfc4122());
    }

    public function save(Role $entity): void
    {
        $em = $this->getEntityManager();
        $em->persist($entity);
        $em->flush();
    }

    public function remove(Role $entity): void
    {
        $em = $this->getEntityManager();
        $em->remove($entity);
        $em->flush();
    }

    public function findAllForTenantWithUserCount(Tenant $tenant): array
    {
        // Roles visible to the tenant = every global role + the tenant's own
        // custom roles. The Settings → Roles list orders them with system
        // (global) roles first (PRD §3.2 macierz prescribes that on-screen
        // grouping), then name ASC.
        //
        // AUD-003 (#1575): the platform-level `platform_operator` role is
        // excluded — it is never assignable inside a tenant (assignment is
        // already rejected by the tenant-scoped findByCode lookup) and must
        // not be surfaced as an option in Settings → Roles.
        //
        // #2881: `super_admin` joins it, for the same reason one exclusion
        // was not enough. It is a GLOBAL row shared by every tenant, so a
        // tenant's Settings → Roles was listing — and offering to edit —
        // an object that does not belong to that tenant. The write path now
        // refuses it, but leaving it on screen only invites the attempt and
        // tells the operator something about the platform they have no
        // business seeing. Assignment was already impossible: the
        // tenant-scoped findByCode lookup does not resolve global codes.
        /** @var list<Role> $roles */
        $roles = $this->createQueryBuilder('r')
            ->where('r.tenant IS NULL OR r.tenant = :tenant')
            ->andWhere('r.code NOT IN (:platformRoles)')
            ->setParameter('tenant', $tenant->getId())
            ->setParameter('platformRoles', [RbacMatrix::ROLE_PLATFORM_OPERATOR, RbacMatrix::ROLE_SUPER_ADMIN])
            ->orderBy('CASE WHEN r.tenant IS NULL THEN 0 ELSE 1 END', 'ASC')
            ->addOrderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();

        if ([] === $roles) {
            return [];
        }

        $userCounts = $this->countUsersByRole($tenant, $roles);

        $out = [];
        foreach ($roles as $role) {
            $roleId = $role->getId()->toRfc4122();
            $out[] = [
                'role' => $role,
                'user_count' => $userCounts[$roleId] ?? 0,
            ];
        }

        return $out;
    }

    /**
     * Single batched COUNT(*) over `user_role_assignments`, the sole record
     * of who holds which role since ADR-0034 (#2832 expand, #2836 contract).
     *
     * @param list<Role> $roles
     *
     * @return array<string, int> roleId (RFC4122) → user count
     */
    private function countUsersByRole(Tenant $tenant, array $roles): array
    {
        $roleIds = array_map(static fn (Role $role): string => $role->getId()->toRfc4122(), $roles);

        /** @var list<array{role_id: mixed, cnt: int|string}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('r.id AS role_id', 'COUNT(u.id) AS cnt')
            ->from(User::class, 'u')
            // ADR-0034 — counts go through the assignment entity.
            ->innerJoin('u.roleAssignments', 'ura')
            ->innerJoin('ura.role', 'r')
            ->where('u.tenant = :tenant')
            ->andWhere('r.id IN (:roleIds)')
            ->groupBy('r.id')
            ->setParameter('tenant', $tenant->getId())
            ->setParameter('roleIds', $roleIds)
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $rawRoleId = $row['role_id'];
            if ($rawRoleId instanceof Uuid) {
                $roleId = $rawRoleId->toRfc4122();
            } elseif (\is_string($rawRoleId)) {
                $roleId = $rawRoleId;
            } else {
                continue;
            }
            $counts[$roleId] = (int) $row['cnt'];
        }

        return $counts;
    }
}
