<?php

declare(strict_types=1);

namespace App\Catalog\Application\Bulk;

use App\Catalog\Application\BulkContext;
use App\Catalog\Application\Reindex\BulkReindexQueueInterface;
use App\Catalog\Domain\Entity\BulkLog;
use App\Catalog\Domain\Entity\BulkSession;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Workflow\Contracts\ObjectEditorialWorkflow;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * WFL-P1-05 (#2419) — bulk `change_status` through the state machine
 * (the follow-up the BulkObjectsController MVP slice deferred). Input
 * is a TRANSITION name, not a target status — semantics of the machine,
 * so topology + RBAC guards (WFL-P1-01) + the completeness gate
 * (WFL-P1-04) are evaluated PER OBJECT via `can()`; a blocked row is a
 * warning BulkLog entry with the blocker reasons, never a silent write
 * (lekcja bulk-endpoint-permission-escalation: no coarse bulk grant may
 * exceed the single-item surface).
 *
 * Runs on the synchronous bulk path like every other bulk action
 * (per-tenant lock, 10k cap, CHUNK_SIZE flush+clear); each apply also
 * rides TransitionLoggingSubscriber, so workflow_transitions rows land
 * with `bulk: true` context per object.
 */
final class BulkChangeStatusHandler extends AbstractBulkHandler
{
    private string $transition = '';
    private ?string $comment = null;

    public function __construct(
        CatalogObjectRepositoryInterface $catalogObjects,
        EntityManagerInterface $em,
        BulkContext $bulkContext,
        BulkReindexQueueInterface $reindexQueue,
        #[Target(ObjectEditorialWorkflow::NAME)]
        private readonly WorkflowInterface $objectEditorial,
    ) {
        parent::__construct($catalogObjects, $em, $bulkContext, $reindexQueue);
    }

    /**
     * @return array{success: int, skipped: int, error: int}
     */
    public function handle(BulkSession $session, string $transition, ?string $comment): array
    {
        $this->transition = $transition;
        $this->comment = $comment;

        return $this->runBatch($session);
    }

    protected function processObject(CatalogObject $object, BulkSession $session, BulkCounters $counters): void
    {
        if (!$this->objectEditorial->can($object, $this->transition)) {
            $reasons = [];
            foreach ($this->objectEditorial->buildTransitionBlockerList($object, $this->transition) as $blocker) {
                $reasons[] = $blocker->getMessage();
            }
            if ([] === $reasons) {
                $reasons[] = \sprintf('transition "%s" is not defined from "%s"', $this->transition, $object->getStatus());
            }

            ++$counters->skipped;
            $this->em->persist(new BulkLog(
                $session->getId(),
                $object->getId(),
                null,
                $object->getStatus(),
                null,
                BulkLog::LEVEL_WARNING,
                'change_status blocked: '.\implode(' ', $reasons),
            ));

            return;
        }

        $before = $object->getStatus();
        $context = ['bulk' => true];
        if (null !== $this->comment) {
            $context['comment'] = $this->comment;
        }
        $this->objectEditorial->apply($object, $this->transition, $context);

        ++$counters->success;
        $this->em->persist(new BulkLog(
            $session->getId(),
            $object->getId(),
            null,
            $before,
            $object->getStatus(),
            BulkLog::LEVEL_INFO,
            \sprintf('change_status: %s (%s -> %s)', $this->transition, $before, $object->getStatus()),
        ));
    }
}
