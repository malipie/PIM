<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine\Repository;

use App\Identity\Domain\Entity\RefreshToken;
use App\Identity\Domain\Repository\RefreshTokenRepositoryInterface;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<RefreshToken>
 */
class DoctrineRefreshTokenRepository extends ServiceEntityRepository implements RefreshTokenRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RefreshToken::class);
    }

    public function findByHash(string $tokenHash): ?RefreshToken
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    /**
     * Mark every still-active token in the family as revoked. Used by
     * RefreshTokenService when a re-used (already `usedAt`) token is presented
     * — refresh-token theft detection. Single UPDATE so the whole family is
     * invalidated atomically; tokens that were already revoked stay untouched.
     */
    public function revokeFamily(Uuid $familyId, DateTimeImmutable $when): void
    {
        $em = $this->getEntityManager();
        $em->createQuery(
            'UPDATE '.RefreshToken::class.' t '
            .'SET t.revokedAt = :when '
            .'WHERE t.familyId = :familyId AND t.revokedAt IS NULL',
        )
            ->setParameter('familyId', $familyId, 'uuid')
            ->setParameter('when', $when)
            ->execute();
    }

    /**
     * DP-02 (#2032) — revoke every still-active token of one user, across
     * families. Used by the admin set-password endpoint so a password reset
     * cuts off any session the previous credential holder might still have.
     * Single UPDATE, mirrors revokeFamily().
     */
    public function revokeAllForUser(Uuid $userId, DateTimeImmutable $when): void
    {
        $em = $this->getEntityManager();
        $em->createQuery(
            'UPDATE '.RefreshToken::class.' t '
            .'SET t.revokedAt = :when '
            .'WHERE t.userId = :userId AND t.revokedAt IS NULL',
        )
            ->setParameter('userId', $userId, 'uuid')
            ->setParameter('when', $when)
            ->execute();
    }

    /**
     * Drop tokens whose `expires_at` is before `$cutoff`. Returns affected row
     * count so a future maintenance command (epic 0.11) can log progress.
     */
    public function purgeExpired(DateTimeImmutable $cutoff): int
    {
        $em = $this->getEntityManager();

        return $em->createQuery(
            'DELETE '.RefreshToken::class.' t WHERE t.expiresAt < :cutoff',
        )
            ->setParameter('cutoff', $cutoff)
            ->execute();
    }

    public function findById(Uuid $id): ?RefreshToken
    {
        return parent::find($id->toRfc4122());
    }

    public function save(RefreshToken $entity): void
    {
        $em = $this->getEntityManager();
        $em->persist($entity);
        $em->flush();
    }

    public function remove(RefreshToken $entity): void
    {
        $em = $this->getEntityManager();
        $em->remove($entity);
        $em->flush();
    }
}
