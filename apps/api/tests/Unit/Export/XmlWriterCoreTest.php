<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export;

use App\Export\Infrastructure\Writer\XmlWriterCore;
use DOMDocument;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * XMLF-P0-03 — XmlWriterCore always emits well-formed XML, even for garbage
 * product data. Escaping is delegated to XMLWriter; this suite pins the extra
 * guarantees the wrapper adds (control-char sanitisation, CDATA neutralisation)
 * and asserts well-formedness by parsing the output with DOMDocument.
 */
final class XmlWriterCoreTest extends TestCase
{
    private static function isWellFormed(string $xml): bool
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $ok = $dom->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return false !== $ok;
    }

    #[Test]
    public function writesUtf8DeclarationAndBasicElement(): void
    {
        $xml = XmlWriterCore::toMemory()
            ->startDocument()
            ->element('name', 'Wkręt ciesielski 4.8×38')
            ->endDocument()
            ->outputMemory();

        self::assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        self::assertStringContainsString('<name>Wkręt ciesielski 4.8×38</name>', $xml);
        self::assertTrue(self::isWellFormed($xml));
    }

    #[Test]
    public function escapesSpecialCharactersInTextAndAttributes(): void
    {
        $xml = XmlWriterCore::toMemory()
            ->startDocument()
            ->startElement('item')
            ->attribute('title', 'A & B "quoted" <tag>')
            ->text('R&D <script>alert(1)</script> 5 < 6')
            ->endElement()
            ->endDocument()
            ->outputMemory();

        self::assertStringContainsString('&amp;', $xml);
        self::assertStringContainsString('&lt;script&gt;', $xml);
        self::assertStringNotContainsString('<script>', $xml);
        self::assertTrue(self::isWellFormed($xml));

        // Round-trips back to the original literal value.
        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $item = $dom->getElementsByTagName('item')->item(0);
        self::assertNotNull($item);
        self::assertSame('R&D <script>alert(1)</script> 5 < 6', $item->textContent);
        self::assertSame('A & B "quoted" <tag>', $item->getAttribute('title'));
    }

    #[Test]
    public function neutralisesCdataTerminatorSoContentCannotEscape(): void
    {
        $payload = 'html <b>bold</b> with a trap ]]> and more';

        $xml = XmlWriterCore::toMemory()
            ->startDocument()
            ->elementCdata('description', $payload)
            ->endDocument()
            ->outputMemory();

        self::assertTrue(self::isWellFormed($xml), 'CDATA with ]]> must stay well-formed');
        self::assertStringContainsString('<![CDATA[', $xml);

        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $desc = $dom->getElementsByTagName('description')->item(0);
        self::assertNotNull($desc);
        // Loss-free: the parser reassembles the original payload verbatim.
        self::assertSame($payload, $desc->textContent);
    }

    #[Test]
    public function stripsControlCharactersIllegalInXml(): void
    {
        // \x0B (vertical tab) and \x00 (NUL) are illegal in XML 1.0; \t \n stay.
        $xml = XmlWriterCore::toMemory()
            ->startDocument()
            ->element('sku', "KL-\x0B\x00CB\t4838\n")
            ->endDocument()
            ->outputMemory();

        self::assertTrue(self::isWellFormed($xml));
        self::assertStringNotContainsString("\x0B", $xml);
        self::assertStringNotContainsString("\x00", $xml);

        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $sku = $dom->getElementsByTagName('sku')->item(0);
        self::assertNotNull($sku);
        self::assertSame("KL-CB\t4838\n", $sku->textContent);
    }

    #[Test]
    public function staysWellFormedOnGarbagePayload(): void
    {
        // Every hostile ingredient at once: CDATA terminator, markup, control
        // char, and an invalid UTF-8 byte (0xFF) from a broken import.
        $garbage = "]]><script>\x0B\xFF evil & <>";

        $xml = XmlWriterCore::toMemory()
            ->startDocument()
            ->startElement('product')
            ->attribute('id', $garbage)
            ->element('name', $garbage)
            ->elementCdata('desc', $garbage)
            ->endElement()
            ->endDocument()
            ->outputMemory();

        self::assertTrue(self::isWellFormed($xml), 'feed must be well-formed even for garbage data');
        self::assertStringNotContainsString("\x0B", $xml);
        self::assertStringNotContainsString("\xFF", $xml);
    }

    #[Test]
    public function streamsToUriAndProducesSameWellFormedDocument(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pim-xml-');
        self::assertIsString($path);

        try {
            XmlWriterCore::toUri($path)
                ->startDocument()
                ->startElement('products')
                ->element('sku', 'KL-CB-4838')
                ->element('sku', 'KL-KP-9090')
                ->endElement()
                ->endDocument();

            $content = (string) file_get_contents($path);
            self::assertTrue(self::isWellFormed($content));
            self::assertStringContainsString('<products>', $content);
            self::assertSame(2, substr_count($content, '<sku>'));
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function outputMemoryRejectedInUriMode(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pim-xml-');
        self::assertIsString($path);

        try {
            $writer = XmlWriterCore::toUri($path)->startDocument()->element('a', 'b')->endDocument();
            $this->expectException(LogicException::class);
            $writer->outputMemory();
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function writesNamespaceDeclarations(): void
    {
        $xml = XmlWriterCore::toMemory()
            ->startDocument()
            ->startElement('rss')
            ->attribute('version', '2.0')
            ->namespaceAttribute('g', 'http://base.google.com/ns/1.0')
            ->endElement()
            ->endDocument()
            ->outputMemory();

        self::assertStringContainsString('xmlns:g="http://base.google.com/ns/1.0"', $xml);
        self::assertTrue(self::isWellFormed($xml));
    }
}
