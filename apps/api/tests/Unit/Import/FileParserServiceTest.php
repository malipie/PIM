<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Import\Application\Service\DelimiterDetector;
use App\Import\Application\Service\EncodingDetector;
use App\Import\Application\Service\FileParserService;
use App\Import\Domain\Enum\FileEncoding;
use App\Import\Domain\Exception\InvalidImportFileException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FileParserServiceTest extends TestCase
{
    #[Test]
    public function parsesTheFestoCsvFixtureWith15HeadersAnd3SampleRows(): void
    {
        $service = $this->parser();
        $path = __DIR__.'/../../fixtures/imports/festo-q2-2026.csv';

        $parsed = $service->parse($path);

        self::assertCount(15, $parsed->headers);
        self::assertSame('Kod produktu', $parsed->headers[0]);
        self::assertSame('Notatki wewn.', $parsed->headers[14]);
        self::assertSame(3, $parsed->totalRows);
        self::assertCount(3, $parsed->sampleRows);
        self::assertSame(';', $parsed->delimiter);
        self::assertSame(FileEncoding::Utf8, $parsed->encoding);
    }

    #[Test]
    public function detectsWindows1250EncodingFromGeneratedFile(): void
    {
        $service = $this->parser();
        $contents = "sku;name\nABC-1;Czujnik ciśnienia\n";
        $cp1250 = iconv('UTF-8', 'Windows-1250', $contents);
        self::assertNotFalse($cp1250);

        $path = $this->writeTempFile($cp1250, '.csv');
        try {
            $parsed = $service->parse($path);
            self::assertSame(FileEncoding::Windows1250, $parsed->encoding);
            self::assertSame('Czujnik ciśnienia', $parsed->sampleRows[0][1]);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function utf8BomIsStrippedFromHeaders(): void
    {
        $service = $this->parser();
        $path = $this->writeTempFile("\xEF\xBB\xBFsku;name\nFOO-1;Sensor\n", '.csv');
        try {
            $parsed = $service->parse($path);
            self::assertSame(['sku', 'name'], $parsed->headers);
            self::assertSame(FileEncoding::Utf8Bom, $parsed->encoding);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function rejectsEmptyCsvFile(): void
    {
        $service = $this->parser();
        $path = $this->writeTempFile('', '.csv');

        try {
            $this->expectException(InvalidImportFileException::class);
            $service->parse($path);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function rejectsUnsupportedExtension(): void
    {
        $service = $this->parser();
        $path = $this->writeTempFile('whatever', '.xls');

        try {
            $this->expectException(InvalidImportFileException::class);
            $this->expectExceptionMessageMatches('/Unsupported import file extension/');
            $service->parse($path);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function rejectsXlsxExtensionCarryingCsvContent(): void
    {
        // AUD-066 (W3-5.2) — extension-only dispatch is spoofable: a CSV body
        // renamed to .xlsx must be rejected by the magic-byte guard before the
        // XLSX reader sees it (a real XLSX always opens with the ZIP signature
        // "PK\x03\x04"; CSV text does not).
        $service = $this->parser();
        $path = $this->writeTempFile("sku;name\nABC-1;Sensor\n", '.xlsx');

        try {
            $this->expectException(InvalidImportFileException::class);
            $this->expectExceptionMessageMatches('/signature|sygnatur|magic|does not match|nie odpowiada/i');
            $service->parse($path);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function acceptsGenuineXlsxWithZipSignature(): void
    {
        // AUD-066 — the positive control: a real XLSX (PK\x03\x04 header)
        // written by openspout passes the magic-byte guard and parses.
        $service = $this->parser();
        $path = $this->writeGenuineXlsx();

        try {
            $parsed = $service->parse($path);
            self::assertSame(['sku', 'name'], $parsed->headers);
            self::assertSame(1, $parsed->totalRows);
            self::assertSame('ABC-1', $parsed->sampleRows[0][0]);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function acceptsGenuineCsvWithoutZipSignature(): void
    {
        // AUD-066 — the symmetric positive control: a plain CSV (no binary
        // signature) keeps parsing unchanged.
        $service = $this->parser();
        $path = $this->writeTempFile("sku;name\nABC-1;Sensor\n", '.csv');

        try {
            $parsed = $service->parse($path);
            self::assertSame(['sku', 'name'], $parsed->headers);
            self::assertSame(1, $parsed->totalRows);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function rejectsCsvExtensionCarryingZipContent(): void
    {
        // AUD-066 — the inverse spoof: a ZIP/XLSX body renamed to .csv. A CSV
        // is text and must never start with the ZIP local-file-header
        // signature; reject before the CSV reader mangles the binary stream.
        $service = $this->parser();
        $xlsxPath = $this->writeGenuineXlsx();
        $csvNamed = $this->writeTempFile((string) file_get_contents($xlsxPath), '.csv');
        @unlink($xlsxPath);

        try {
            $this->expectException(InvalidImportFileException::class);
            $this->expectExceptionMessageMatches('/signature|sygnatur|magic|does not match|nie odpowiada/i');
            $service->parse($csvNamed);
        } finally {
            @unlink($csvNamed);
        }
    }

    private function parser(): FileParserService
    {
        return new FileParserService(new EncodingDetector(), new DelimiterDetector());
    }

    /**
     * #2808 — the regression that made this necessary: a 51 800-row export
     * took 200 s to preview because the parser iterated every row to count
     * them, and PHP's 30 s limit turned that into a fatal error (HTML 500,
     * not Problem Details). Above the threshold the count now comes from the
     * sheet's declared dimension.
     */
    #[Test]
    public function largeSheetIsCountedFromDeclaredDimensionWithoutReadingEveryRow(): void
    {
        $rows = 6_000; // above DIMENSION_COUNT_THRESHOLD
        $path = $this->writeXlsxWithRows($rows);

        try {
            $parsed = $this->parser()->parse($path);

            self::assertSame(['sku', 'name'], $parsed->headers);
            self::assertSame($rows, $parsed->totalRows, 'declared dimension must exclude the header row');
            self::assertCount(5, $parsed->sampleRows, 'samples are still collected on the fast path');
            self::assertSame('SKU-1', $parsed->sampleRows[0][0]);
        } finally {
            @unlink($path);
        }
    }

    /**
     * The fast path must not change what small files report — they were never
     * the problem and their exact count costs nothing.
     */
    #[Test]
    public function smallSheetKeepsTheExactIteratedCount(): void
    {
        $rows = 12; // below the threshold
        $path = $this->writeXlsxWithRows($rows);

        try {
            $parsed = $this->parser()->parse($path);

            self::assertSame($rows, $parsed->totalRows);
            self::assertCount(5, $parsed->sampleRows);
        } finally {
            @unlink($path);
        }
    }

    private function writeXlsxWithRows(int $dataRows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'imp-test-').'.xlsx';
        $writer = new XlsxWriter();
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['sku', 'name']));
        for ($i = 1; $i <= $dataRows; ++$i) {
            $writer->addRow(Row::fromValues(['SKU-'.$i, 'Product '.$i]));
        }
        $writer->close();

        return $path;
    }

    private function writeGenuineXlsx(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'imp-test-').'.xlsx';
        $writer = new XlsxWriter();
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['sku', 'name']));
        $writer->addRow(Row::fromValues(['ABC-1', 'Sensor']));
        $writer->close();

        return $path;
    }

    private function writeTempFile(string $contents, string $suffix): string
    {
        $path = tempnam(sys_get_temp_dir(), 'imp-test-');
        self::assertNotFalse($path);
        $renamed = $path.$suffix;
        rename($path, $renamed);
        file_put_contents($renamed, $contents);

        return $renamed;
    }
}
