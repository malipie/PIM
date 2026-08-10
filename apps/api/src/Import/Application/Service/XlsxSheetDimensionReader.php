<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use ZipArchive;

/**
 * Reads the declared row count of an XLSX's first sheet from the file's own
 * metadata, without parsing a single cell (#2808).
 *
 * Why this exists: the import wizard's preview needs three things — headers,
 * a handful of sample rows, and a row count. The first two are cheap. The
 * count was not: {@see FileParserService} iterated every row of the sheet to
 * increment a counter, which on a 51 800-row export (12 MB, 377k shared
 * strings) took **200 seconds** and blew through PHP's 30 s limit as a fatal
 * error — the wizard answered 500 with an HTML page instead of JSON.
 *
 * Measured on that file: iterating to count took 199 s, reading the
 * `<dimension>` element takes **1 ms** and yields exactly the same number the
 * iterator arrives at. Skipping `toArray()` on non-sample rows saves nothing
 * (199 s vs 204 s) — the cost is OpenSpout's row iteration itself, so no time
 * limit would have made the old approach acceptable anyway.
 *
 * Accuracy caveat, deliberately accepted: `<dimension>` describes the sheet's
 * used range, so a workbook whose author left trailing blank rows reports
 * more rows than the reader would yield. The preview count is informational —
 * {@see \App\Import\Application\Handler\ImportRunHandler} counts rows itself
 * during the real run and that is what the session and progress bar report.
 * Trading a few phantom rows in a preview estimate for a 200-second request
 * is the right way round.
 *
 * Every failure path returns null so the caller falls back to counting.
 */
final readonly class XlsxSheetDimensionReader
{
    /**
     * How much of the sheet XML to inspect. `<dimension>` is emitted by the
     * spec right after `<worksheet>`, so the opening kilobytes are enough;
     * reading further would defeat the purpose.
     */
    private const int HEAD_BYTES = 4096;

    /**
     * @return int|null number of rows in the first sheet's used range, or null
     *                  when the file does not declare a usable one
     */
    public function lastRowNumber(string $absolutePath): ?int
    {
        $zip = new ZipArchive();
        if (true !== $zip->open($absolutePath)) {
            return null;
        }

        try {
            $sheetPath = $this->firstSheetPath($zip);
            if (null === $sheetPath) {
                return null;
            }

            $head = $this->readHead($zip, $sheetPath);
            if (null === $head) {
                return null;
            }

            if (1 !== preg_match('/<dimension[^>]*\bref="[A-Z]+\d+(?::[A-Z]+(\d+))?"/', $head, $matches)) {
                return null;
            }

            // `ref="A1"` (single cell) means the writer declared an empty or
            // unknown range — no capture group, nothing to trust.
            $lastRow = $matches[1] ?? null;
            if (null === $lastRow) {
                return null;
            }

            $rows = (int) $lastRow;

            return $rows > 0 ? $rows : null;
        } finally {
            $zip->close();
        }
    }

    /**
     * Resolves the first sheet through workbook.xml + its relationships
     * rather than assuming `sheet1.xml`. Workbooks written by other tools
     * order sheet parts independently of their display order, and counting
     * the wrong sheet would be worse than not counting at all.
     */
    private function firstSheetPath(ZipArchive $zip): ?string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        if (!\is_string($workbook)
            || 1 !== preg_match('/<sheet\b[^>]*r:id="([^"]+)"/', $workbook, $sheetMatch)) {
            return null;
        }

        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if (!\is_string($rels)) {
            return null;
        }

        $pattern = \sprintf('/<Relationship\b[^>]*Id="%s"[^>]*Target="([^"]+)"/', preg_quote($sheetMatch[1], '/'));
        if (1 !== preg_match($pattern, $rels, $relMatch)) {
            return null;
        }

        $target = ltrim($relMatch[1], '/');
        // Targets are relative to xl/ unless already absolute in the package.
        $candidate = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;

        return false === $zip->locateName($candidate) ? null : $candidate;
    }

    private function readHead(ZipArchive $zip, string $entry): ?string
    {
        $stream = $zip->getStream($entry);
        if (!\is_resource($stream)) {
            return null;
        }

        try {
            $head = fread($stream, self::HEAD_BYTES);

            return \is_string($head) && '' !== $head ? $head : null;
        } finally {
            fclose($stream);
        }
    }
}
