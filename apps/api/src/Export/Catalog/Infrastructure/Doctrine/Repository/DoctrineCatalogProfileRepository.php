<?php

declare(strict_types=1);

namespace App\Export\Catalog\Infrastructure\Doctrine\Repository;

use App\Export\Catalog\Domain\Entity\CatalogProfile;
use App\Export\Catalog\Domain\Repository\CatalogProfileRepositoryInterface;
use App\Shared\Domain\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<CatalogProfile>
 */
class DoctrineCatalogProfileRepository extends ServiceEntityRepository implements CatalogProfileRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CatalogProfile::class);
    }

    public function save(CatalogProfile $profile): void
    {
        $em = $this->getEntityManager();
        $em->persist($profile);
        $em->flush();
    }

    public function remove(CatalogProfile $profile): void
    {
        $em = $this->getEntityManager();
        $em->remove($profile);
        $em->flush();
    }

    public function findById(Uuid $id): ?CatalogProfile
    {
        return parent::find($id->toRfc4122());
    }

    public function findByTokenHash(string $tokenHash): ?CatalogProfile
    {
        // Tenant scoping is enforced upstream (RLS GUC + TenantFilter set by the
        // public controller from the URL); this is a plain indexed lookup that
        // only sees the current tenant's rows.
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    public function findByTenantAndCode(Tenant $tenant, string $code): ?CatalogProfile
    {
        return $this->findOneBy(['tenant' => $tenant, 'code' => $code]);
    }

    /**
     * @return list<CatalogProfile>
     */
    public function findByTenant(Tenant $tenant): array
    {
        $qb = $this->createQueryBuilder('c')
            ->where('c.tenant = :tenant')
            ->orderBy('c.updatedAt', 'DESC')
            ->setParameter('tenant', $tenant);

        /** @var list<CatalogProfile> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }
}
