<?php

declare(strict_types=1);

namespace App\Export\Infrastructure\Writer;

/**
 * Adapts the positional {@see RowWriter} (CSV/XLSX) to the associative
 * {@see RowSink} the runner drives (XMLF-P0-05). Maps each associative row onto
 * the ordered column list — the mapping the runner used to do inline.
 */
final class PositionalRowSink implements RowSink
{
    /** @var list<string> */
    private array $columns = [];

    public function __construct(private readonly RowWriter $writer)
    {
    }

    public function begin(array $columns): void
    {
        $this->columns = $columns;
        $this->writer->writeHeaders($columns);
    }

    public function accept(array $row): void
    {
        $values = [];
        foreach ($this->columns as $key) {
            $values[] = $row[$key] ?? '';
        }

        $this->writer->writeRow($values);
    }

    public function close(): void
    {
        $this->writer->close();
    }
}
