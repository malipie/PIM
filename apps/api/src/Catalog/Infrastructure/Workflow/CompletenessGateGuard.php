<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Workflow;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Workflow\Contracts\ObjectEditorialWorkflow;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\TransitionBlocker;

/**
 * WFL-P1-04 (#2418) — completeness gate on publishing transitions
 * (ADR-0029 pillar 6, benchmark: Ergonode conditions / Akeneo entry
 * criteria / Salsify readiness). Reads the per-ObjectType config
 * (`workflow_publish_gate`, default OFF) against the denormalised
 * completeness read model (`objects.completeness` + `completeness_pct`,
 * docs/api/jsonb-schemas.md §3) — no recomputation on the hot path.
 *
 * Lives in Catalog (not the Workflow BC): the gate needs the entity's
 * completeness payload and its ObjectType config, which the
 * EditorialWorkflowSubject seam deliberately does not expose.
 */
final readonly class CompletenessGateGuard implements EventSubscriberInterface
{
    public const string BLOCKER_CODE = 'completeness_gate';

    /**
     * @var list<string>
     */
    private const array GATED_TRANSITIONS = [
        ObjectEditorialWorkflow::TRANSITION_PUBLISH,
        ObjectEditorialWorkflow::TRANSITION_APPROVE,
    ];

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.'.ObjectEditorialWorkflow::NAME.'.guard' => 'onGuard',
        ];
    }

    public function onGuard(GuardEvent $event): void
    {
        if (!\in_array($event->getTransition()->getName(), self::GATED_TRANSITIONS, true)) {
            return;
        }

        $subject = $event->getSubject();
        if (!$subject instanceof CatalogObject) {
            return;
        }

        $gate = $subject->getObjectType()->getWorkflowPublishGate();
        if (null === $gate || true !== ($gate['enabled'] ?? false)) {
            return;
        }

        $minPct = \is_int($gate['min_completeness_pct'] ?? null) ? $gate['min_completeness_pct'] : 100;
        $scope = \is_string($gate['scope'] ?? null) ? $gate['scope'] : 'global';

        $failures = [];
        if ('per_channel' === $scope) {
            $channels = \is_array($gate['channels'] ?? null) ? $gate['channels'] : [];
            $perChannel = $subject->getCompleteness()['per_channel'] ?? [];
            $perChannel = \is_array($perChannel) ? $perChannel : [];
            foreach ($channels as $channel) {
                if (!\is_string($channel)) {
                    continue;
                }
                // Contract §3: per_channel is optional — a channel with no
                // computed pct falls back to the global mirror.
                $pct = $perChannel[$channel] ?? $subject->getCompletenessPct();
                $pct = \is_int($pct) ? $pct : 0;
                if ($pct < $minPct) {
                    $failures[] = \sprintf('%s: %d%%', $channel, $pct);
                }
            }
        } elseif ($subject->getCompletenessPct() < $minPct) {
            $failures[] = \sprintf('global: %d%%', $subject->getCompletenessPct());
        }

        if ([] === $failures) {
            return;
        }

        $missing = $this->missingRequiredCodes($subject);

        $event->addTransitionBlocker(new TransitionBlocker(
            \sprintf(
                'Completeness below the publish gate (min %d%%): %s.%s',
                $minPct,
                \implode(', ', $failures),
                [] === $missing ? '' : ' Missing required: '.\implode(', ', $missing).'.',
            ),
            self::BLOCKER_CODE,
        ));
    }

    /**
     * Required attribute codes (ObjectType.completeness_rules.required)
     * that have no non-empty value in the denormalised attribute index —
     * the actionable part of the 409 for the FE tooltip (WFL-P3-01).
     *
     * @return list<string>
     */
    private function missingRequiredCodes(CatalogObject $subject): array
    {
        $rules = $subject->getObjectType()->getCompletenessRules();
        $required = \is_array($rules['required'] ?? null) ? $rules['required'] : [];
        $indexed = $subject->getAttributesIndexed();

        $missing = [];
        foreach ($required as $code) {
            if (!\is_string($code)) {
                continue;
            }
            $value = $indexed[$code] ?? null;
            if (null === $value || '' === $value || [] === $value) {
                $missing[] = $code;
            }
        }

        return $missing;
    }
}
