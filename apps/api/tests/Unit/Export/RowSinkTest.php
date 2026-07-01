<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export;

use App\Export\Application\Builder\ColumnDefinition;
use App\Export\Infrastructure\Writer\GenericXmlWriter;
use App\Export\Infrastructure\Writer\PositionalRowSink;
use App\Export\Infrastructure\Writer\RowWriter;
use App\Export\Infrastructure\Writer\XmlRowSink;
use App\Export\Infrastructure\Writer\XmlWriterCore;
use DOMDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * XMLF-P0-05 — the RowSink adapters let the runner drive every format from a
 * single associative-row loop: positional (CSV/XLSX) maps onto the column
 * order, XML feeds the row straight to the ItemWriter.
 */
final class RowSinkTest extends TestCase
{
    #[Test]
    public function positionalSinkMapsAssociativeRowOntoColumnOrder(): void
    {
        $writer = new class implements RowWriter {
            /** @var list<list<string>> */
            public array $rows = [];

            public function writeHeaders(array $headers): void
            {
                $this->rows[] = $headers;
            }

            public function writeRow(array $values): void
            {
                $this->rows[] = $values;
            }

            public function close(): void
            {
            }
        };

        $sink = new PositionalRowSink($writer);
        $sink->begin(['sku', 'name', 'ean']);
        $sink->accept(['name' => 'Wkręt', 'sku' => 'KL-1']); // out of order, 'ean' missing
        $sink->close();

        self::assertSame(['sku', 'name', 'ean'], $writer->rows[0]);
        self::assertSame(['KL-1', 'Wkręt', ''], $writer->rows[1]);
    }

    #[Test]
    public function xmlSinkStreamsAssociativeRowsAsWellFormedXml(): void
    {
        $xml = XmlWriterCore::toMemory();
        $columns = [
            new ColumnDefinition('sku', ColumnDefinition::KIND_BUILT_IN, 'sku'),
            new ColumnDefinition('name', ColumnDefinition::KIND_BUILT_IN, 'name'),
        ];

        $sink = new XmlRowSink(new GenericXmlWriter($xml, $columns));
        $sink->begin(['sku', 'name']);
        $sink->accept(['sku' => 'KL-1', 'name' => 'A & B']);
        $sink->accept(['sku' => 'KL-2', 'name' => 'C']);
        $sink->close();

        $out = $xml->outputMemory();
        $dom = new DOMDocument();
        self::assertNotFalse($dom->loadXML($out));
        self::assertSame(2, $dom->getElementsByTagName('product')->length);
        self::assertSame('A & B', $dom->getElementsByTagName('name')->item(0)?->textContent);
    }
}
