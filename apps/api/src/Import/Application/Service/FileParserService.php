<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Domain\Enum\FileEncoding;
use App\Import\Domain\Exception\InvalidImportFileException;
use App\Import\Domain\ValueObject\ParsedFile;
use League\Csv\Reader;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

use const PATHINFO_EXTENSION;

/**
 * Parses an uploaded CSV / xlsx file and returns the headers, the
 * first 5 rows, and the total row count. Single-purpose by design:
 * the validation + async run handlers (IMP-03 / IMP-04) re-open the
 * file streamingly, so this service stays focused on the wizard's
 * Step 1 + Step 2 needs.
 *
 * xlsx behaviour (spec §7.1): only the first sheet is read; if the
 * workbook ships multiple sheets, the {@see ParsedFile::$hadMultipleSheets}
 * flag tells the wizard to surface the warning copy.
 */
final class FileParserService
{
    private const int SAMPLE_ROWS = 5;

    /**
     * Above this many rows the preview stops counting by iteration and trusts
     * the sheet's declared dimension (#2808). Small files keep the exact
     * count they always had — the fast path only kicks in where the old one
     * was heading for a 30-second fatal.
     */
    private const int DIMENSION_COUNT_THRESHOLD = 5_000;
    private const int DETECT_PREFIX_BYTES = 16384;
    private const array XLSX_EXTENSIONS = ['xlsx'];
    private const array CSV_EXTENSIONS = ['csv'];

    /**
     * AUD-066 (W3-5.2) — ZIP local-file-header magic bytes. An XLSX is a ZIP
     * archive and always opens with this signature. The two alternative ZIP
     * end-of-central-directory ("PK\x05\x06", empty archive) and spanned
     * ("PK\x07\x08") markers also start with "PK", but a freshly written
     * single-file XLSX always leads with the local-file-header below.
     */
    private const string ZIP_SIGNATURE = "PK\x03\x04";

    public function __construct(
        private readonly EncodingDetector $encodingDetector,
        private readonly DelimiterDetector $delimiterDetector,
        private readonly XlsxSheetDimensionReader $dimensions = new XlsxSheetDimensionReader(),
    ) {
    }

    public function parse(string $absolutePath, ?FileEncoding $encodingOverride = null, ?string $delimiterOverride = null): ParsedFile
    {
        if (!is_readable($absolutePath)) {
            throw InvalidImportFileException::unreadable($absolutePath);
        }

        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        // AUD-066: extension-based dispatch is spoofable. Before picking a
        // parser, verify the file's leading bytes are consistent with its
        // extension — an XLSX must carry the ZIP signature, a CSV must not.
        // The XlsxArchiveGuard (zip-bomb) and OpenSpout's own "not a zip"
        // failure already backstop the XLSX path; this is a cheap, explicit
        // first line of defence that also protects the CSV path (a binary
        // stream renamed to .csv would otherwise be silently mis-parsed).
        $this->assertSignatureMatchesExtension($absolutePath, $extension);

        if (\in_array($extension, self::XLSX_EXTENSIONS, true)) {
            return $this->parseXlsx($absolutePath);
        }
        if (\in_array($extension, self::CSV_EXTENSIONS, true)) {
            return $this->parseCsv($absolutePath, $encodingOverride, $delimiterOverride);
        }

        throw InvalidImportFileException::unsupportedExtension($extension);
    }

    /**
     * AUD-066 — lightweight magic-byte check. Only known extensions are
     * inspected; the `unsupportedExtension` guard above handles the rest.
     *
     *   - .xlsx → MUST start with the ZIP local-file-header "PK\x03\x04".
     *   - .csv  → MUST NOT start with that ZIP signature (a renamed archive).
     */
    private function assertSignatureMatchesExtension(string $absolutePath, string $extension): void
    {
        if (!\in_array($extension, self::XLSX_EXTENSIONS, true)
            && !\in_array($extension, self::CSV_EXTENSIONS, true)
        ) {
            return;
        }

        $handle = fopen($absolutePath, 'r');
        if (false === $handle) {
            throw InvalidImportFileException::unreadable($absolutePath);
        }
        try {
            $signature = fread($handle, \strlen(self::ZIP_SIGNATURE));
        } finally {
            fclose($handle);
        }
        $signature = false === $signature ? '' : $signature;

        $looksLikeZip = str_starts_with($signature, self::ZIP_SIGNATURE);

        if (\in_array($extension, self::XLSX_EXTENSIONS, true) && !$looksLikeZip) {
            throw InvalidImportFileException::signatureMismatch($extension);
        }
        if (\in_array($extension, self::CSV_EXTENSIONS, true) && $looksLikeZip) {
            throw InvalidImportFileException::signatureMismatch($extension);
        }
    }

    private function parseXlsx(string $absolutePath): ParsedFile
    {
        // #2808 — ask the sheet how many rows it has BEFORE opening a reader.
        // Counting by iteration costs ~200 s on a 51 800-row export, which the
        // 30 s request limit turns into a fatal error (HTML 500, not even a
        // Problem Details body). When the file declares a large range we read
        // headers + samples and stop; small files keep the exact count they
        // always had, since iterating them is free.
        $declaredRows = $this->dimensions->lastRowNumber($absolutePath);
        $useDeclaredCount = null !== $declaredRows && $declaredRows > self::DIMENSION_COUNT_THRESHOLD;

        // IMP2-2.1 — stream the first sheet with openspout (no full-workbook
        // load). Header dedup mirrors ImportRowReader so the wizard maps against
        // the exact labels the run keys cells by.
        try {
            $reader = new XlsxReader(new XlsxOptions(SHOULD_FORMAT_DATES: false));
            $reader->open($absolutePath);
        } catch (Throwable $exception) {
            throw InvalidImportFileException::corrupted($absolutePath, $exception);
        }

        /** @var list<string> $headers */
        $headers = [];
        /** @var list<list<string|null>> $sampleRows */
        $sampleRows = [];
        $totalRows = 0;
        $sheetName = null;
        $sheetCount = 0;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                ++$sheetCount;
                if ($sheetCount > 1) {
                    continue; // only the first sheet is read; count the rest for the flag
                }
                $sheetName = $sheet->getName();
                $rowNumber = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    ++$rowNumber;
                    $values = $row->toArray();
                    if (1 === $rowNumber) {
                        $headers = HeaderNormalizer::deduplicate(array_values(array_map(
                            static fn (mixed $v): string => \is_scalar($v) ? (string) $v : '',
                            $values,
                        )));

                        continue;
                    }
                    ++$totalRows;
                    if (\count($sampleRows) < self::SAMPLE_ROWS) {
                        $sampleRows[] = $this->positionalSample($headers, $values);

                        continue;
                    }

                    // Samples are complete; on the declared-count path there is
                    // nothing left to learn from the remaining rows.
                    if ($useDeclaredCount) {
                        break 2;
                    }
                }
            }
        } finally {
            $reader->close();
        }

        if ([] === $headers || [] === array_filter($headers, static fn (string $h): bool => '' !== $h)) {
            throw InvalidImportFileException::noHeaderRow();
        }

        // The declared range counts the header row too.
        if ($useDeclaredCount) {
            $totalRows = max(0, $declaredRows - 1);
        }

        return new ParsedFile(
            headers: $headers,
            sampleRows: $sampleRows,
            totalRows: $totalRows,
            encoding: FileEncoding::Utf8,
            delimiter: null,
            sheetName: $sheetName,
            hadMultipleSheets: $sheetCount > 1,
        );
    }

    private function readPrefix(string $absolutePath): string
    {
        $handle = fopen($absolutePath, 'r');
        if (false === $handle) {
            throw InvalidImportFileException::unreadable($absolutePath);
        }
        try {
            $prefix = fread($handle, self::DETECT_PREFIX_BYTES);
        } finally {
            fclose($handle);
        }

        return false === $prefix ? '' : $prefix;
    }

    /**
     * Build a positional preview row aligned to the (deduplicated) header list.
     *
     * @param list<string>      $headers
     * @param array<int, mixed> $values
     *
     * @return list<string|null>
     */
    private function positionalSample(array $headers, array $values): array
    {
        $row = [];
        foreach (array_keys($headers) as $i) {
            $value = $values[$i] ?? null;
            $string = \is_scalar($value) ? (string) $value : '';
            $row[] = '' === $string ? null : $string;
        }

        return $row;
    }

    private function parseCsv(string $absolutePath, ?FileEncoding $encodingOverride, ?string $delimiterOverride): ParsedFile
    {
        // IMP2-2.1 — stream from the path with an iconv read filter; detect
        // encoding + delimiter from a bounded prefix (no whole-file read).
        $prefix = $this->readPrefix($absolutePath);
        if ('' === trim($prefix)) {
            throw InvalidImportFileException::empty();
        }
        $encoding = $encodingOverride ?? $this->encodingDetector->detect($prefix);
        $delimiter = $delimiterOverride ?? $this->delimiterDetector->detect($this->encodingDetector->stripBom($prefix));

        $reader = Reader::from($absolutePath, 'r');
        if (FileEncoding::Utf8 !== $encoding && FileEncoding::Utf8Bom !== $encoding) {
            $reader->appendStreamFilterOnRead('convert.iconv.'.$encoding->iconvName().'/UTF-8//TRANSLIT');
        }
        $reader->setDelimiter($delimiter);

        // Read positionally (see ImportRowReader::iterateCsv): a header passed to
        // getRecords() trips league/csv's duplicate-column guard on repeated
        // blank columns that real exports carry (bosch-09-01-2026.csv).
        $headers = null;
        $sampleRows = [];
        $totalRows = 0;
        foreach ($reader->getRecords() as $record) {
            $values = array_values($record);
            if (null === $headers) {
                $headers = HeaderNormalizer::deduplicate(array_map(
                    static fn (mixed $v): string => \is_scalar($v) ? (string) $v : '',
                    $values,
                ));
                if ([] === array_filter($headers, static fn (string $h): bool => '' !== $h)) {
                    throw InvalidImportFileException::noHeaderRow();
                }

                continue;
            }
            ++$totalRows;
            if (\count($sampleRows) < self::SAMPLE_ROWS) {
                $sampleRows[] = $this->positionalSample($headers, $values);
            }
        }

        if (null === $headers) {
            throw InvalidImportFileException::noHeaderRow();
        }

        return new ParsedFile(
            headers: $headers,
            sampleRows: $sampleRows,
            totalRows: $totalRows,
            encoding: $encoding,
            delimiter: $delimiter,
            sheetName: null,
            hadMultipleSheets: false,
        );
    }
}
