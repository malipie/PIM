<?php

declare(strict_types=1);

namespace App\Export\Feed\Domain\Generator;

use App\Export\Feed\Domain\Descriptor\FeedDescriptor;

/**
 * Per-product feed-health validation (ADR-0023 §6.5, XMLF-P2-04): checks an
 * item map against the descriptor's required / requiredOneOf / maxLength rules.
 * Returns one violation per broken rule (slot + message) for the FeedRunLog.
 */
final class FeedRequiredValidator
{
    /**
     * @param array<string, string> $item slot target => rendered value
     *
     * @return list<array{slot: string, message: string}>
     */
    public function check(FeedDescriptor $descriptor, array $item): array
    {
        $violations = [];
        $oneOf = [];

        foreach ($descriptor->slots as $slot) {
            $value = $item[$slot->target] ?? '';

            if ($slot->rule->required && '' === $value) {
                $violations[] = ['slot' => $slot->target, 'message' => sprintf('missing required %s', $slot->target)];
            }

            if ([] !== $slot->rule->requiredOneOf) {
                $key = implode('|', $slot->rule->requiredOneOf);
                $oneOf[$key] = ($oneOf[$key] ?? false) || '' !== $value;
            }

            if (null !== $slot->rule->maxLength && mb_strlen($value) > $slot->rule->maxLength) {
                $violations[] = ['slot' => $slot->target, 'message' => sprintf('%s exceeds max length %d', $slot->target, $slot->rule->maxLength)];
            }
        }

        foreach ($oneOf as $key => $satisfied) {
            if (!$satisfied) {
                $violations[] = ['slot' => $key, 'message' => sprintf('none of required-one-of [%s] is set', $key)];
            }
        }

        return $violations;
    }
}
