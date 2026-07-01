<?php

declare(strict_types=1);

namespace App\Export\Infrastructure\Writer;

use App\Export\Application\Builder\ColumnDefinition;
use App\Export\Contracts\ItemWriter;

/**
 * Generic XML writer for the ad-hoc export mode (XMLF-P0-04/P0-05, ADR-0023
 * §6.10). Produces a schema-less `<products><product>…</product></products>`
 * dump — no feed template, no transformations. The feed path uses the
 * descriptor-driven `XmlFeedWriter` (XMLF-P2-05) instead.
 *
 * Each column becomes an element named after its attribute/built-in code; a
 * `.locale` / `.channel` scoped column carries the scope as element attributes
 * (`<description locale="pl">`, `<price channel="shopify">`) because `.` is not
 * a legal XML element name character. Multi-value cells keep the pipe join
 * ExportBuilder/ValueSerializer already produced, so the XML dump round-trips
 * consistently with CSV/XLSX.
 *
 * All serialization goes through {@see XmlWriterCore}, so the output is always
 * well-formed (escaping + control-char sanitisation) even for garbage data.
 */
final class GenericXmlWriter implements ItemWriter
{
    /**
     * @param list<ColumnDefinition> $columns resolved export column plan
     */
    public function __construct(
        private readonly XmlWriterCore $xml,
        private readonly array $columns,
        private readonly string $rootElement = 'products',
        private readonly string $itemElement = 'product',
    ) {
    }

    public function begin(): void
    {
        $this->xml->startDocument()->startElement($this->rootElement);
    }

    public function writeItem(array $item): void
    {
        $this->xml->startElement($this->itemElement);

        foreach ($this->columns as $column) {
            $value = $item[$column->key] ?? '';

            $this->xml->startElement(self::elementName($column->code));
            if (null !== $column->locale) {
                $this->xml->attribute('locale', $column->locale);
            }
            if (null !== $column->channel) {
                $this->xml->attribute('channel', $column->channel);
            }
            $this->xml->text($value);
            $this->xml->endElement();
        }

        $this->xml->endElement();
    }

    public function finish(): void
    {
        $this->xml->endElement()->endDocument();
    }

    /**
     * Coerce a column code into a legal XML element name (NCName): start with a
     * letter or underscore, then letters / digits / `-` / `_`. Attribute codes
     * are normally already valid; this is a defensive fallback.
     */
    private static function elementName(string $code): string
    {
        $name = preg_replace('/[^A-Za-z0-9_-]/', '_', $code) ?? '';

        if ('' === $name || 1 !== preg_match('/^[A-Za-z_]/', $name)) {
            $name = '_'.$name;
        }

        return $name;
    }
}
