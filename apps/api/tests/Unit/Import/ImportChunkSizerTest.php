<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Import\Application\Service\ImportChunkSizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #2813 — the import chunked by row count, so a wide file carried an order of
 * magnitude more values between flushes than a narrow one and exhausted the
 * worker's 256 MiB ceiling at 27% of a 51 800-row export.
 */
final class ImportChunkSizerTest extends TestCase
{
    /**
     * The property that matters: values held between flushes stay bounded no
     * matter how wide the mapping is. Asserting the row counts alone would
     * pass for a formula that happens to return the right numbers today and
     * drift silently later.
     */
    #[Test]
    #[DataProvider('mappingWidths')]
    public function workingSetStaysBoundedAcrossFileShapes(int $columns): void
    {
        $rows = ImportChunkSizer::rowsPerChunk($columns, 200);
        $valuesPerChunk = $rows * $columns;

        self::assertLessThanOrEqual(
            ImportChunkSizer::TARGET_VALUES_PER_CHUNK + ($columns * ImportChunkSizer::MIN_ROWS),
            $valuesPerChunk,
            \sprintf('%d columns × %d rows = %d values held between flushes', $columns, $rows, $valuesPerChunk),
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function mappingWidths(): iterable
    {
        yield 'minimal supplier file' => [2];
        yield 'typical supplier file' => [8];
        yield 'rich catalogue' => [24];
        yield 'full export' => [69];
        yield 'pathological' => [400];
    }

    /**
     * Narrow files must keep the cadence they already had — the fix is meant
     * to bound wide imports, not to slow down the common case by flushing
     * more often than before.
     */
    #[Test]
    public function narrowMappingsKeepTheConfiguredRowCadence(): void
    {
        self::assertSame(200, ImportChunkSizer::rowsPerChunk(2, 200));
        self::assertSame(200, ImportChunkSizer::rowsPerChunk(8, 200));
    }

    #[Test]
    public function wideMappingsShrinkTheChunk(): void
    {
        self::assertSame(66, ImportChunkSizer::rowsPerChunk(24, 200));
        self::assertSame(23, ImportChunkSizer::rowsPerChunk(69, 200));
    }

    /**
     * A pathologically wide mapping must not shrink the chunk to a handful of
     * rows: per-chunk query overhead would then dominate and the import would
     * trade an OOM for an unfinishable run.
     */
    #[Test]
    public function pathologicalMappingsStopAtTheFloor(): void
    {
        self::assertSame(ImportChunkSizer::MIN_ROWS, ImportChunkSizer::rowsPerChunk(5_000, 200));
    }

    /**
     * The caller's cadence is an upper bound, never raised. A deployment that
     * deliberately lowered the batch size must keep that setting.
     */
    #[Test]
    public function neverExceedsTheCallersCadence(): void
    {
        self::assertSame(10, ImportChunkSizer::rowsPerChunk(1, 10));
        self::assertSame(10, ImportChunkSizer::rowsPerChunk(500, 10));
    }

    #[Test]
    public function degenerateInputsDoNotProduceZeroSizedChunks(): void
    {
        self::assertGreaterThan(0, ImportChunkSizer::rowsPerChunk(0, 200));
        self::assertGreaterThan(0, ImportChunkSizer::rowsPerChunk(-5, 200));
        self::assertGreaterThan(0, ImportChunkSizer::rowsPerChunk(69, 0));
    }
}
