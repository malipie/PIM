<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\Usage;

/**
 * Shapes raw DBAL rows into the `where-used` payload fragments.
 *
 * Extracted from {@see UsageQueryService} in #3034: the per-item loaders and
 * the batched loaders return byte-identical payloads, which only holds if they
 * normalise rows through the same code. Making that shared step explicit keeps
 * the parity guaranteed by construction rather than by two copies staying in
 * step — `ModelingUsageApiTest` asserts the parity, this class is why it holds.
 */
final class UsageRowNormalizer
{
    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{id: string, code: string, label: array<string, string>}>
     */
    public static function normalizeGroupRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $rawId = $row['id'] ?? '';
            $rawCode = $row['code'] ?? '';
            $id = \is_scalar($rawId) ? (string) $rawId : '';
            $code = \is_scalar($rawCode) ? (string) $rawCode : '';
            $label = $row['label'] ?? null;
            if (\is_string($label)) {
                $decoded = json_decode($label, true);
                $label = \is_array($decoded) ? $decoded : [];
            }
            if (!\is_array($label)) {
                $label = [];
            }
            $cleanLabel = [];
            foreach ($label as $k => $v) {
                if (\is_string($k) && \is_string($v)) {
                    $cleanLabel[$k] = $v;
                }
            }
            $out[] = ['id' => $id, 'code' => $code, 'label' => $cleanLabel];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{id: string, code: string, kind: string}>
     */
    public static function normalizeObjectTypeRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => self::scalarString($row['id'] ?? null),
                'code' => self::scalarString($row['code'] ?? null),
                'kind' => self::scalarString($row['kind'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{id: string, path: string|null}>
     */
    public static function normalizeCategoryRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $path = $row['path'] ?? null;
            $out[] = [
                'id' => self::scalarString($row['id'] ?? null),
                'path' => \is_string($path) ? $path : null,
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{id: string, path: string|null, target_kind: string|null}>
     */
    public static function normalizeCategoryAttachmentRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $path = $row['path'] ?? null;
            $kind = $row['target_kind'] ?? null;
            $out[] = [
                'id' => self::scalarString($row['id'] ?? null),
                'path' => \is_string($path) ? $path : null,
                'target_kind' => \is_string($kind) ? $kind : null,
            ];
        }

        return $out;
    }

    private static function scalarString(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
