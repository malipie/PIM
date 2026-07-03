<?php

declare(strict_types=1);

namespace App\Agent\Application\Tool;

/**
 * #2153 — shared selector precedence for write tools. The operator works
 * on a list with a SELECTION (checked rows) and/or an active view filter;
 * the agent must act on what the operator means, not blindly on the whole
 * view.
 *
 * Precedence (narrowest deliberate intent first):
 *   1. explicit `object_ids` tool arg  — the model listed exact ids
 *   2. explicit `filter_dsl` tool arg  — the model chose a broader scope
 *   3. context `selected_ids`          — the operator's current selection
 *   4. context `filter_dsl`            — the active view
 *   5. nothing                         — every object of the type
 *
 * Returns [selectedIds, filterDsl]: at most one is "set" (selectedIds
 * non-null XOR a non-empty filterDsl); the engine validates selectedIds
 * against tenant + object type, so a stray id can never widen the scope.
 */
trait ResolvesSelectionScope
{
    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{0: list<string>|null, 1: array<string, mixed>} [selectedIds, filterDsl]
     */
    private function resolveScope(array $arguments, AgentToolContext $context): array
    {
        $argIds = $this->stringList($arguments['object_ids'] ?? null);
        if (null !== $argIds) {
            return [$argIds, []];
        }

        if (\is_array($arguments['filter_dsl'] ?? null)) {
            /** @var array<string, mixed> $filter */
            $filter = $arguments['filter_dsl'];

            return [null, $filter];
        }

        $selection = $this->stringList($context->viewContext['selected_ids'] ?? null);
        if (null !== $selection) {
            return [$selection, []];
        }

        if (\is_array($context->viewContext['filter_dsl'] ?? null)) {
            /** @var array<string, mixed> $viewFilter */
            $viewFilter = $context->viewContext['filter_dsl'];

            return [null, $viewFilter];
        }

        return [null, []];
    }

    /**
     * Non-empty list of non-empty strings, or null when the input is not a
     * usable id list (missing, empty, or all-invalid). Null means "no
     * selection at this precedence level" so resolution falls through.
     *
     * @return list<string>|null
     */
    private function stringList(mixed $raw): ?array
    {
        if (!\is_array($raw)) {
            return null;
        }
        $out = [];
        foreach ($raw as $value) {
            if (\is_string($value) && '' !== $value) {
                $out[] = $value;
            }
        }

        return [] === $out ? null : $out;
    }
}
