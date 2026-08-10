<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

/**
 * Decides how many rows an import chunk may hold (#2813).
 *
 * The batch cadence inherited from {@see \App\Shared\Application\AbstractBatchHandler}
 * counts ROWS, which is the wrong unit for the import: 200 rows of a
 * six-column supplier file hold ~1 200 values, while 200 rows of a 69-column
 * export hold ~13 800. The working set between flushes — not the row count —
 * is what drove the worker into its 256 MiB ceiling on a re-import of a full
 * export, and it is why ordinary supplier files never showed the problem.
 *
 * Measured on the shape that failed (69 mapped columns, 50k projection):
 *
 *   row-based cadence:    peak 212.5 MiB, projected 321.6 MiB — over the ceiling
 *   value-based cadence:  peak  76.5 MiB, projected 150.1 MiB — and 4× faster,
 *                         because Doctrine computes change sets across every
 *                         managed entity on flush, so a smaller unit of work
 *                         makes each flush cheaper as well as lighter.
 *
 * Narrow files keep exactly the cadence they had: the row cap still applies,
 * so nothing changes until a mapping is wide enough to matter.
 */
final readonly class ImportChunkSizer
{
    /**
     * Values a chunk may hold before it is flushed.
     *
     * Chosen as the working set a 200-row × 8-column import already produced
     * in production without trouble — this bounds wide files to a known-good
     * size rather than to a guess.
     */
    public const int TARGET_VALUES_PER_CHUNK = 1_600;

    /**
     * Floor for the row cadence. Below this the per-chunk query overhead
     * (`resolveMany`, `primeChunk`, undo capture) starts to dominate and a
     * very wide file would trade memory for a much longer run.
     */
    public const int MIN_ROWS = 20;

    /**
     * @param int $mappedColumns how many source columns the run writes per row
     * @param int $maxRows       the handler's configured row cadence (upper bound)
     */
    public static function rowsPerChunk(int $mappedColumns, int $maxRows): int
    {
        $columns = max(1, $mappedColumns);
        $maxRows = max(1, $maxRows);

        return max(
            min(self::MIN_ROWS, $maxRows),
            min($maxRows, intdiv(self::TARGET_VALUES_PER_CHUNK, $columns)),
        );
    }
}
