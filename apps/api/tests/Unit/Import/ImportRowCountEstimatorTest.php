<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Import\Application\Service\ImportRowCountEstimator;
use App\Import\Application\Service\XlsxSheetDimensionReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * #2815 — the size of an import must be knowable before the run works through
 * it, because `total_rows` was previously written only after the row loop: for
 * the whole time it would have been useful the column said NULL.
 */
final class ImportRowCountEstimatorTest extends TestCase
{
    /** @var list<string> */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        $this->tempPaths = [];

        parent::tearDown();
    }

    #[Test]
    public function csvCountsDataRowsExcludingTheHeader(): void
    {
        $path = $this->tempFile('csv', "sku;name\nA-1;One\nA-2;Two\nA-3;Three\n");

        self::assertSame(3, $this->estimator()->estimate($path));
    }

    #[Test]
    public function csvIgnoresBlankLines(): void
    {
        // Trailing newlines are what an export tool leaves behind; counting them
        // would put the progress bar's denominator above the real work.
        $path = $this->tempFile('csv', "sku;name\nA-1;One\n\n\nA-2;Two\n\n");

        self::assertSame(2, $this->estimator()->estimate($path));
    }

    #[Test]
    public function headerOnlyCsvIsZeroRowsNotAnEstimateOfOne(): void
    {
        $path = $this->tempFile('csv', "sku;name\n");

        self::assertSame(0, $this->estimator()->estimate($path));
    }

    #[Test]
    public function xlsxIsReadFromTheSheetDimensionRatherThanByIterating(): void
    {
        // The declared range covers rows 1..1500; row 1 is the header, so the
        // run has 1499 data rows to do. Reading this costs a millisecond —
        // iterating the same sheet to count took 200 s (#2808).
        $path = $this->xlsxWithDimension('A1:C1500');

        self::assertSame(1499, $this->estimator()->estimate($path));
    }

    #[Test]
    public function unusableXlsxDimensionYieldsNoEstimate(): void
    {
        // `ref="A1"` is what a writer emits when it does not know the range.
        // Guessing here would be worse than saying nothing: the caller leaves
        // total_rows NULL and the exact count still lands at the end of the run.
        $path = $this->xlsxWithDimension('A1');

        self::assertNull($this->estimator()->estimate($path));
    }

    #[Test]
    public function unknownExtensionYieldsNoEstimate(): void
    {
        $path = $this->tempFile('txt', "sku;name\nA-1;One\n");

        self::assertNull($this->estimator()->estimate($path));
    }

    #[Test]
    public function missingFileYieldsNoEstimate(): void
    {
        self::assertNull($this->estimator()->estimate('/tmp/does-not-exist-'.uniqid().'.csv'));
    }

    private function estimator(): ImportRowCountEstimator
    {
        return new ImportRowCountEstimator(new XlsxSheetDimensionReader());
    }

    private function tempFile(string $extension, string $contents): string
    {
        $path = \sprintf('%s/import-estimate-%s.%s', sys_get_temp_dir(), uniqid(), $extension);
        file_put_contents($path, $contents);
        $this->tempPaths[] = $path;

        return $path;
    }

    /** Minimal XLSX package: workbook, its relationships, and one sheet. */
    private function xlsxWithDimension(string $ref): string
    {
        $path = \sprintf('%s/import-estimate-%s.xlsx', sys_get_temp_dir(), uniqid());
        $this->tempPaths[] = $path;

        $zip = new ZipArchive();
        self::assertTrue(true === $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString(
            'xl/workbook.xml',
            '<?xml version="1.0"?><workbook xmlns:r="r"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>',
        );
        $zip->addFromString(
            'xl/_rels/workbook.xml.rels',
            '<?xml version="1.0"?><Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>',
        );
        $zip->addFromString(
            'xl/worksheets/sheet1.xml',
            \sprintf('<?xml version="1.0"?><worksheet><dimension ref="%s"/><sheetData/></worksheet>', $ref),
        );
        $zip->close();

        return $path;
    }
}
