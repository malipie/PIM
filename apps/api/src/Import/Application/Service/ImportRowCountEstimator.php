<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use const PATHINFO_EXTENSION;

/**
 * #2815 — how many data rows a staged file holds, answered before the run
 * starts rather than after it finishes.
 *
 * `import_sessions.total_rows` used to be written only once the row loop had
 * completed, so for the entire time it would have been useful — while the
 * import was running — the column said NULL and the sessions list rendered
 * "— wierszy" next to a job that had been working for twenty minutes.
 *
 * Both formats can answer cheaply:
 *   - XLSX through {@see XlsxSheetDimensionReader}, which reads the sheet's
 *     declared range in about a millisecond (#2808);
 *   - CSV by counting newlines, which costs one sequential read and no parsing.
 *
 * The result is an ESTIMATE and is treated as one. A workbook with trailing
 * blank rows over-reports; a CSV with quoted line breaks inside a field
 * over-reports too, because counting lines is not parsing records. The run
 * overwrites the column with the exact count when the loop ends
 * ({@see \App\Import\Application\Handler\ImportRunHandler}), so the estimate
 * only ever fills the window where the alternative was showing nothing at all.
 */
final readonly class ImportRowCountEstimator
{
    public function __construct(private XlsxSheetDimensionReader $xlsxDimensions)
    {
    }

    /**
     * @return int|null data rows excluding the header, or null when the file
     *                  gives no cheap answer (the caller then leaves total_rows
     *                  unset rather than publishing a number it invented)
     */
    public function estimate(string $absolutePath): ?int
    {
        if (!is_readable($absolutePath)) {
            return null;
        }

        return match (strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION))) {
            'xlsx' => $this->fromSheetDimension($absolutePath),
            'csv' => $this->fromLineCount($absolutePath),
            default => null,
        };
    }

    private function fromSheetDimension(string $absolutePath): ?int
    {
        $lastRow = $this->xlsxDimensions->lastRowNumber($absolutePath);
        if (null === $lastRow) {
            return null;
        }

        // Row 1 is the header, so a one-row sheet carries no data.
        return max(0, $lastRow - 1);
    }

    private function fromLineCount(string $absolutePath): ?int
    {
        $handle = @fopen($absolutePath, 'r');
        if (false === $handle) {
            return null;
        }

        $rows = -1; // the header line
        try {
            while (false !== ($line = fgets($handle))) {
                if ('' === trim($line)) {
                    continue;
                }
                ++$rows;
            }
        } finally {
            fclose($handle);
        }

        return max(0, $rows);
    }
}
