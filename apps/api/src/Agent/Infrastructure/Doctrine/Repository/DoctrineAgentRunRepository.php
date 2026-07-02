<?php

declare(strict_types=1);

namespace App\Agent\Infrastructure\Doctrine\Repository;

use App\Agent\Domain\Entity\AgentRun;
use App\Agent\Domain\Repository\AgentRunRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineAgentRunRepository implements AgentRunRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(AgentRun $run): void
    {
        $this->entityManager->persist($run);
        $this->entityManager->flush();
    }

    public function find(Uuid $id): ?AgentRun
    {
        // find()-by-PK skips the TenantFilter via the identity map (APIC
        // P5-01 lesson) — query through DQL so the filter always applies.
        /** @var AgentRun|null $run */
        $run = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(AgentRun::class, 'r')
            ->where('r.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $run;
    }
}
