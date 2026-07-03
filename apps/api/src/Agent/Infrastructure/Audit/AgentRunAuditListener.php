<?php

declare(strict_types=1);

namespace App\Agent\Infrastructure\Audit;

use App\Agent\Domain\Entity\AgentRun;
use App\Identity\Contracts\Audit\AgentActionAuditor;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;

/**
 * AGENT-P3-07 (#1967) — every persisted AgentRun lands in the DH
 * Auditor as agent_run_started, regardless of who created it (loop
 * starter, API endpoint, CLI). postPersist keeps the wiring in ONE
 * place instead of every creation site; decision events (commit /
 * reject / cancel / rollback) are recorded by AgentApprovalService
 * where the decision context (approver) lives.
 *
 * The auditor's save() flushes, and flushing inside a flush is
 * forbidden — so postPersist only BUFFERS the run facts and postFlush
 * writes them. Draining the buffer before recording keeps the nested
 * flush (audit save -> postFlush) a no-op instead of a recursion.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postFlush)]
final class AgentRunAuditListener
{
    /** @var list<array{runId: string, actorId: string, details: array<string, mixed>}> */
    private array $buffer = [];

    public function __construct(
        private readonly AgentActionAuditor $auditor,
    ) {
    }

    public function postPersist(PostPersistEventArgs $event): void
    {
        $entity = $event->getObject();
        if (!$entity instanceof AgentRun) {
            return;
        }

        $this->buffer[] = [
            'runId' => $entity->getId()->toRfc4122(),
            'actorId' => $entity->getUserId()->toRfc4122(),
            'details' => [
                'surface' => $entity->getSurface()->value,
                'intent' => $entity->getIntent(),
                'model' => $entity->getModel(),
            ],
        ];
    }

    public function postFlush(PostFlushEventArgs $event): void
    {
        if ([] === $this->buffer) {
            return;
        }

        $drained = $this->buffer;
        $this->buffer = [];

        foreach ($drained as $entry) {
            $this->auditor->recordAgentAction('agent_run_started', $entry['runId'], $entry['actorId'], $entry['details']);
        }
    }
}
