<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Workflow;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Service\EffectiveAttributeGroupResolver;
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
     * #2558 — submit-time required-fields blocker. Distinct from the numeric
     * publish gate: a product missing required attributes cannot be published,
     * so it must not enter the review queue.
     */
    public const string BLOCKER_MISSING_REQUIRED = 'missing_required';

    /**
     * @var list<string>
     */
    private const array GATED_TRANSITIONS = [
        ObjectEditorialWorkflow::TRANSITION_PUBLISH,
        ObjectEditorialWorkflow::TRANSITION_APPROVE,
    ];

    public function __construct(
        private EffectiveAttributeGroupResolver $attributeGroups,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.'.ObjectEditorialWorkflow::NAME.'.guard' => 'onGuard',
        ];
    }

    public function onGuard(GuardEvent $event): void
    {
        $subject = $event->getSubject();
        if (!$subject instanceof CatalogObject) {
            return;
        }

        // #2558 — an object missing a field that is actually required in its
        // current form cannot enter review: it would strand a reviewer who
        // cannot approve it. Completeness rules are a separate readiness
        // metric; they must not turn an optional form field (for example EAN)
        // into a submission blocker.
        if (ObjectEditorialWorkflow::TRANSITION_SUBMIT_FOR_REVIEW === $event->getTransition()->getName()) {
            $missing = $this->missingRequiredFormCodes($subject);
            if ([] !== $missing) {
                $event->addTransitionBlocker(new TransitionBlocker(
                    \sprintf('Fill the required fields before review: %s.', \implode(', ', $missing)),
                    self::BLOCKER_MISSING_REQUIRED,
                    ['missing_required' => $missing],
                ));
            }

            return;
        }

        // Tenant definitions (WFL-P5-01) attach a per-transition gate as
        // metadata; the static machine gates publish/approve from the
        // ObjectType config. Metadata wins when present.
        $metadataGate = $event->getMetadata('completeness_gate', $event->getTransition());
        if (\is_array($metadataGate)) {
            $gate = $metadataGate + ['enabled' => true];
        } else {
            if (!\in_array($event->getTransition()->getName(), self::GATED_TRANSITIONS, true)) {
                return;
            }
            $gate = $subject->getObjectType()->getWorkflowPublishGate();
        }

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

        $missing = $this->missingCompletenessCodes($subject);

        $event->addTransitionBlocker(new TransitionBlocker(
            \sprintf(
                'Completeness below the publish gate (min %d%%): %s.%s',
                $minPct,
                \implode(', ', $failures),
                [] === $missing ? '' : ' Missing required: '.\implode(', ', $missing).'.',
            ),
            self::BLOCKER_CODE,
            // #2558 — structured params so the SPA can render a localized
            // message instead of the raw English blocker text.
            [
                'min_completeness_pct' => $minPct,
                'missing_required' => $missing,
            ],
        ));
    }

    /**
     * Required attribute codes (ObjectType.completeness_rules.required)
     * that have no non-empty value in the denormalised attribute index —
     * the actionable part of the 409 for the FE tooltip (WFL-P3-01).
     *
     * @return list<string>
     */
    private function missingCompletenessCodes(CatalogObject $subject): array
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

    /**
     * Required fields are defined by the effective form schema: an attribute
     * can be required globally or only in a particular group. This mirrors the
     * product editor's save validation, including category-derived groups.
     *
     * @return list<string>
     */
    private function missingRequiredFormCodes(CatalogObject $subject): array
    {
        $groups = $this->attributeGroups->resolve($subject);
        $byGroup = $this->attributeGroups->loadGroupAttributes($groups);
        $indexed = $subject->getAttributesIndexed();

        $missing = [];
        foreach ($byGroup as $junctions) {
            foreach ($junctions as $junction) {
                $attribute = $junction->getAttribute();
                if (!$attribute->isRequired() && !$junction->isRequiredInGroup()) {
                    continue;
                }

                $code = $attribute->getCode();
                $value = $indexed[$code] ?? null;
                if (null === $value || '' === $value || [] === $value) {
                    $missing[$code] = true;
                }
            }
        }

        return array_keys($missing);
    }
}
