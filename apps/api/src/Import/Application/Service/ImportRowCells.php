<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Domain\ReservedMappingTarget;
use App\Import\Domain\ValueObject\ResolvedImportValue;

/**
 * #2737 — reads the reserved (non-attribute) meaning out of one import row.
 *
 * These are pure lookups over the raw cells plus the column mapping: which
 * categories the row names, whether they append or replace, the status /
 * enabled flags, the variant axes, the SKU and a snippet for error logs. They
 * had accumulated in {@see \App\Import\Application\Handler\ImportRunHandler}
 * alongside the chunk loop, the circuit breaker and the media pipeline; pulled
 * out here they are independently readable and testable, and the handler keeps
 * only orchestration.
 *
 * Stateless by design — every method is a function of its arguments, so the
 * handler can call them per row without lifecycle concerns.
 */
final class ImportRowCells
{
    /**
     * IMP2-1.7 — pipe-split list of category codes from the cell mapped to a
     * reserved category target (`code-a|code-b|code-c`). Trimmed, empties
     * dropped, order preserved (first becomes primary). The validator emits a
     * per-code CategoryNotFound warning; unresolved codes are simply absent
     * from the resolved set the writer assigns.
     *
     * @param array<string, string|null> $cells
     * @param array<string, string>      $columnMapping
     *
     * @return list<string>
     */
    public function extractCategoryCodes(array $cells, array $columnMapping): array
    {
        foreach ($columnMapping as $columnHeader => $target) {
            if (!ReservedMappingTarget::isCategory($target)) {
                continue;
            }
            $cell = $cells[$columnHeader] ?? null;
            if (null === $cell || '' === $cell) {
                continue;
            }

            // Pipe or newline (#1719) — external exports pack category lists
            // with embedded newlines in one quoted cell.
            return MultiValueSplitter::split($cell);
        }

        return [];
    }

    /**
     * IMP2-1.7 (D2 collection policy) — true when the category column maps to
     * the append target; default (plain `__category__`) is replace.
     *
     * @param array<string, string> $columnMapping
     */
    public function categoryAppend(array $columnMapping): bool
    {
        return \in_array(ReservedMappingTarget::CATEGORY_APPEND, $columnMapping, true);
    }

    /**
     * IMP2-1.7 — validated publication status pulled from the `__status__`
     * column (lower-cased), or null when the column is absent/empty (D2 — do
     * not touch). The validator already rejected out-of-enum values.
     *
     * @param array<string, string|null> $cells
     * @param array<string, string>      $columnMapping
     */
    public function extractStatus(array $cells, array $columnMapping): ?string
    {
        $raw = $this->reservedCell($cells, $columnMapping, ReservedMappingTarget::STATUS);

        return null === $raw ? null : strtolower($raw);
    }

    /**
     * IMP2-1.7 — enabled flag from the `__enabled__` column, or null when
     * absent/empty. Accepts true|1 (→true) / false|0 (→false); the validator
     * rejected anything else.
     *
     * @param array<string, string|null> $cells
     * @param array<string, string>      $columnMapping
     */
    public function extractEnabled(array $cells, array $columnMapping): ?bool
    {
        $raw = $this->reservedCell($cells, $columnMapping, ReservedMappingTarget::ENABLED);
        if (null === $raw) {
            return null;
        }

        return \in_array(strtolower($raw), ['1', 'true'], true);
    }

    /**
     * IMP2-1.8 — parse the `__variant_axes__` cell (`code:v1,v2|code:v3`) into
     * the stored shape, or null when absent/empty (D2 — do not touch).
     *
     * @param array<string, string|null> $cells
     * @param array<string, string>      $columnMapping
     *
     * @return ?list<array{code: string, values: list<string>}>
     */
    public function extractVariantAxes(array $cells, array $columnMapping): ?array
    {
        $raw = $this->reservedCell($cells, $columnMapping, ReservedMappingTarget::VARIANT_AXES);
        if (null === $raw) {
            return null;
        }

        $axes = [];
        foreach (explode('|', $raw) as $part) {
            $part = trim($part);
            if ('' === $part) {
                continue;
            }
            [$code, $valuesRaw] = array_pad(explode(':', $part, 2), 2, '');
            $code = trim($code);
            if ('' === $code) {
                continue;
            }
            $values = array_values(array_filter(
                array_map('trim', explode(',', $valuesRaw)),
                static fn (string $value): bool => '' !== $value,
            ));
            $axes[] = ['code' => $code, 'values' => $values];
        }

        return [] === $axes ? null : $axes;
    }

    /**
     * First non-empty (trimmed) cell whose mapping targets the given reserved
     * marker, or null.
     *
     * @param array<string, string|null> $cells
     * @param array<string, string>      $columnMapping
     */
    public function reservedCell(array $cells, array $columnMapping, string $target): ?string
    {
        foreach ($columnMapping as $columnHeader => $mapped) {
            if ($mapped !== $target) {
                continue;
            }
            $cell = $cells[$columnHeader] ?? null;
            if (null !== $cell && '' !== trim($cell)) {
                return trim($cell);
            }
        }

        return null;
    }

    /**
     * SKU is non-localised — the first resolved `sku` value with content
     * wins. Falls back to a synthetic code so a row that somehow passed
     * validation without one still persists rather than collides on an
     * empty unique key.
     *
     * @param list<ResolvedImportValue> $resolvedValues
     */
    public function skuFrom(array $resolvedValues, int $rowNumber): string
    {
        foreach ($resolvedValues as $resolved) {
            if ('sku' === $resolved->attributeCode
                && null !== $resolved->rawValue
                && '' !== $resolved->rawValue) {
                return $resolved->rawValue;
            }
        }

        return \sprintf('IMPORT-%d', $rowNumber);
    }

    /**
     * @param array<string, string> $columnMapping
     */
    public function skuColumnHeader(array $columnMapping): string
    {
        foreach ($columnMapping as $header => $attributeCode) {
            if ('sku' === $attributeCode) {
                return $header;
            }
        }

        return 'sku';
    }

    /**
     * IMP2-1.9 — a compact rendering of the raw cells for the `columnValue`
     * of a parse-failure log, so the operator can identify the offending
     * (e.g. section/junk) row in the report. Truncated to keep logs sane.
     *
     * @param array<string, string|null> $cells
     */
    public function rawRowSnippet(array $cells): string
    {
        $snippet = implode(' | ', array_map(
            static fn (?string $value): string => $value ?? '',
            array_values($cells),
        ));

        return mb_substr($snippet, 0, 500);
    }
}
