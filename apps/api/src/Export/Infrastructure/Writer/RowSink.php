<?php

declare(strict_types=1);

namespace App\Export\Infrastructure\Writer;

/**
 * Row-oriented sink the export runner writes to (XMLF-P0-05).
 *
 * The runner yields an ASSOCIATIVE row (`array<string,string>` keyed by column
 * key) from {@see \App\Export\Application\Builder\ExportBuilder}. A sink hides
 * the format-specific shape: positional formats (CSV/XLSX) map the row onto the
 * ordered column list, while XML feeds the associative row straight to an
 * {@see \App\Export\Contracts\ItemWriter}. This keeps the runner's paging /
 * clear() / progress loop identical across formats.
 */
interface RowSink
{
    /**
     * Open the document + header/wrapper structure.
     *
     * @param list<string> $columns ordered export column keys
     */
    public function begin(array $columns): void;

    /**
     * Write one associative row.
     *
     * @param array<string, string> $row column-key => serialized value
     */
    public function accept(array $row): void;

    public function close(): void;
}
