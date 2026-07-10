<?php

declare(strict_types=1);

namespace App\Workflow\Infrastructure\EventSubscriber;

use App\Catalog\Contracts\Workflow\EditorialWorkflowSubject;
use App\Identity\Contracts\Auth\CurrentUserProvider;
use App\Workflow\Contracts\ObjectEditorialWorkflow;
use App\Workflow\Domain\Entity\WorkflowTransition;
use App\Workflow\Domain\Repository\WorkflowTransitionRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\TransitionEvent;

/**
 * WFL-P0-04 (#2413) — appends a `workflow_transitions` row for every
 * applied `object_editorial` transition (ADR-0029 pillar 5). Listens on
 * the `transition` event (after guards passed, before the marking store
 * writes) because the subject still carries the ACTUAL source place —
 * `archive` declares two froms (draft|published) and the completed
 * event can no longer tell which one the object left. Nothing can veto
 * a transition past the guard stage, so the row never records a
 * transition that did not happen. The row is persist-only and rides
 * the caller's flush (PATCH handler save / transition endpoint save /
 * bulk batch flush).
 *
 * Actor comes from the security token via the AUD-053 seam — NULL for
 * system-applied transitions (CLI, workers). `comment` and any extra
 * keys arrive through `Workflow::apply($subject, $name, $context)`.
 */
final readonly class TransitionLoggingSubscriber implements EventSubscriberInterface
{
    private const string COMMENT_KEY = 'comment';

    public function __construct(
        private WorkflowTransitionRepositoryInterface $transitions,
        private CurrentUserProvider $currentUser,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.'.ObjectEditorialWorkflow::NAME.'.transition' => 'onTransition',
        ];
    }

    public function onTransition(TransitionEvent $event): void
    {
        $subject = $event->getSubject();
        if (!$subject instanceof EditorialWorkflowSubject) {
            return;
        }

        // Unit-constructed subjects (topology tests) have no tenant; a
        // tenantless row could never satisfy RLS, so skip logging.
        if (null === $subject->getTenant()) {
            return;
        }

        $transition = $event->getTransition();
        if (null === $transition) {
            return;
        }

        $fromPlaces = \array_keys($event->getMarking()->getPlaces());
        $fromPlace = \is_string($fromPlaces[0] ?? null)
            ? $fromPlaces[0]
            : \implode(',', \array_filter($transition->getFroms(), \is_string(...)));

        $context = $event->getContext();
        $comment = $context[self::COMMENT_KEY] ?? null;
        $comment = \is_string($comment) && '' !== \trim($comment) ? \trim($comment) : null;
        unset($context[self::COMMENT_KEY]);

        // Explicit re-key instead of an inline @var — the cs-fixer
        // degrades non-declaration doc-blocks and PHPStan then ignores
        // them (lesson from DASH #2266).
        $typedContext = [];
        foreach ($context as $key => $value) {
            $typedContext[(string) $key] = $value;
        }

        $this->transitions->add(new WorkflowTransition(
            objectId: $subject->getId(),
            workflowName: ObjectEditorialWorkflow::NAME,
            transition: $transition->getName(),
            fromPlace: $fromPlace,
            toPlace: \implode(',', \array_filter($transition->getTos(), \is_string(...))),
            actorUserId: $this->currentUser->userId(),
            comment: $comment,
            context: [] === $typedContext ? null : $typedContext,
        ));
    }
}
