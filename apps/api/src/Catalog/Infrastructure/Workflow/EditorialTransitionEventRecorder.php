<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Workflow;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Workflow\Contracts\ObjectEditorialWorkflow;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\TransitionEvent;

/**
 * WFL-P2-01 (#2420) — records transition-specific aggregate events for
 * the `object_editorial` machine. The marking-store writer only sees
 * the TARGET place (reject and unpublish both land in draft), so the
 * transition name lives here; publish/approve/archive keep their
 * events in {@see CatalogObject::setEditorialMarking()} — one source
 * per event, no duplicates. Events ride the existing post-flush
 * pipeline (DomainEventDispatcher → Messenger → MercurePublisher and,
 * from WFL-P2-02 on, the notification fan-out).
 */
final readonly class EditorialTransitionEventRecorder implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.'.ObjectEditorialWorkflow::NAME.'.transition' => 'onTransition',
        ];
    }

    public function onTransition(TransitionEvent $event): void
    {
        $subject = $event->getSubject();
        if (!$subject instanceof CatalogObject) {
            return;
        }

        $transition = $event->getTransition();
        if (null === $transition) {
            return;
        }

        $context = $event->getContext();
        $comment = $context['comment'] ?? null;
        $comment = \is_string($comment) ? $comment : null;

        match ($transition->getName()) {
            ObjectEditorialWorkflow::TRANSITION_SUBMIT_FOR_REVIEW => $subject->recordSubmittedForReview($comment),
            ObjectEditorialWorkflow::TRANSITION_REJECT => $subject->recordRejected($comment),
            ObjectEditorialWorkflow::TRANSITION_UNPUBLISH => $subject->recordUnpublished(
                true === ($context['auto_unpublish'] ?? false),
            ),
            ObjectEditorialWorkflow::TRANSITION_RESTORE => $subject->recordRestored(),
            default => null,
        };
    }
}
