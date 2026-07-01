<?php

declare(strict_types=1);

namespace App\Export\Infrastructure\Writer;

use App\Export\Contracts\ItemWriter;

/**
 * Adapts an {@see ItemWriter} (associative XML writer) to the {@see RowSink}
 * the runner drives (XMLF-P0-05). The associative row is passed through
 * unchanged — the writer owns element/attribute naming from its column plan.
 */
final class XmlRowSink implements RowSink
{
    public function __construct(private readonly ItemWriter $writer)
    {
    }

    public function begin(array $columns): void
    {
        // The XML writer holds its own column plan / descriptor; the ordered
        // key list is not needed to open the document.
        $this->writer->begin();
    }

    public function accept(array $row): void
    {
        $this->writer->writeItem($row);
    }

    public function close(): void
    {
        $this->writer->finish();
    }
}
