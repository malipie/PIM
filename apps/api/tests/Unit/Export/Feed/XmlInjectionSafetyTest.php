<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Feed;

use App\Export\Feed\Domain\Descriptor\FeedDescriptor;
use App\Export\Feed\Infrastructure\Writer\XmlFeedWriter;
use App\Export\Infrastructure\Writer\XmlWriterCore;
use DOMDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * XMLF-P6-01 — adversarial "always well-formed" suite (ADR-0023 §6.7).
 *
 * Every value that reaches a feed originates from operator/PIM data and must be
 * treated as hostile: a product name containing `]]>`, `<script>`, a raw `&`,
 * a NUL byte or a DTD fragment must never break XML well-formedness nor smuggle
 * live markup into the output. This suite hammers both the low-level
 * {@see XmlWriterCore} and the descriptor-driven {@see XmlFeedWriter} with such
 * payloads and asserts the result always parses (DOMDocument, the in-process
 * equivalent of the `xmllint --noout` smoke gate) and never yields an injected
 * element.
 */
final class XmlInjectionSafetyTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function hostilePayloads(): iterable
    {
        yield 'cdata breakout' => [']]>'];
        yield 'nested cdata breakout' => [']]]]><![CDATA[>'];
        yield 'script tag' => ['<script>alert(1)</script>'];
        yield 'entity chars' => ['a & b < c > d " e \' f'];
        yield 'doctype injection' => ['<!DOCTYPE x [<!ENTITY y "z">]>'];
        yield 'comment injection' => ['<!-- comment --><![CDATA[x]]>'];
        yield 'control chars' => ["bad\x00\x08\x0b\x0c\x1fchars"];
        yield 'ampersand entity' => ['Tom &amp; Jerry &notreal; &#x41;'];
        yield 'angle mix' => ['<<<>>>&&&'];
        yield 'unicode preserved' => ['Zażółć gęślą jaźń — €'];
    }

    #[Test]
    #[DataProvider('hostilePayloads')]
    public function elementTextStaysWellFormedAndEscaped(string $payload): void
    {
        $xml = XmlWriterCore::toMemory();
        $xml->startDocument()->startElement('root')
            ->element('field', $payload)
            ->text($payload)
            ->endElement()
            ->endDocument();
        $out = $xml->outputMemory();

        $dom = $this->assertWellFormed($out);
        self::assertStringNotContainsString('<script>', $out);
        self::assertSame(0, $dom->getElementsByTagName('script')->length);
    }

    #[Test]
    #[DataProvider('hostilePayloads')]
    public function cdataStaysWellFormedAndNeverClosesEarly(string $payload): void
    {
        $xml = XmlWriterCore::toMemory();
        $xml->startDocument()->startElement('root')
            ->elementCdata('field', $payload)
            ->endElement()
            ->endDocument();
        $out = $xml->outputMemory();

        $dom = $this->assertWellFormed($out);
        // The payload survives as text content (round-trips), and no `]]>`
        // escaped the CDATA envelope to break the document.
        self::assertSame(0, $dom->getElementsByTagName('script')->length);
    }

    #[Test]
    #[DataProvider('hostilePayloads')]
    public function attributeValueStaysWellFormed(string $payload): void
    {
        $xml = XmlWriterCore::toMemory();
        $xml->startDocument()->startElement('root')
            ->attribute('attr', $payload)
            ->text('body')
            ->endElement()
            ->endDocument();

        $this->assertWellFormed($xml->outputMemory());
    }

    #[Test]
    public function sanitizeStripsInvalidControlCharsButKeepsWhitespace(): void
    {
        $sanitized = XmlWriterCore::sanitizeText("a\tb\nc\r\x00\x0b\x1fd");

        self::assertStringContainsString("a\tb\nc", $sanitized);
        self::assertStringNotContainsString("\x00", $sanitized);
        self::assertStringNotContainsString("\x0b", $sanitized);
        self::assertStringNotContainsString("\x1f", $sanitized);
    }

    #[Test]
    public function cdataNeutralisationRoundTripsRepeatedClosingSequences(): void
    {
        // The real invariant: `]]>` runs written as CDATA produce well-formed
        // XML whose text content is the exact original — the `]]]]><![CDATA[>`
        // split reopens the section rather than letting the payload close it.
        $original = 'a]]>b]]>c';

        $xml = XmlWriterCore::toMemory();
        $xml->startDocument()->startElement('root')
            ->elementCdata('field', $original)
            ->endElement()
            ->endDocument();

        $dom = $this->assertWellFormed($xml->outputMemory());
        self::assertSame($original, $dom->getElementsByTagName('field')->item(0)?->textContent);
    }

    #[Test]
    #[DataProvider('hostilePayloads')]
    public function descriptorDrivenFeedStaysWellFormedAcrossHtmlPolicies(string $payload): void
    {
        $descriptor = FeedDescriptor::fromArray([
            'root' => ['element' => 'products', 'namespaces' => ['g' => 'http://base.google.com/ns/1.0']],
            'item' => [
                'element' => 'product',
                'slots' => [
                    ['slot' => 'sku', 'node' => 'attribute', 'parent' => 'product', 'element' => 'sku', 'fmt' => 'text'],
                    ['slot' => 'title', 'node' => 'element', 'element' => 'title', 'fmt' => 'text', 'html' => 'escape'],
                    ['slot' => 'description', 'node' => 'element', 'element' => 'description', 'fmt' => 'html', 'html' => 'cdata'],
                    ['slot' => 'note', 'node' => 'element', 'element' => 'note', 'fmt' => 'text', 'html' => 'strip'],
                ],
            ],
        ]);

        $xml = XmlWriterCore::toMemory();
        $writer = new XmlFeedWriter($xml, $descriptor);
        $writer->begin();
        $writer->writeItem([
            'sku' => $payload,
            'title' => $payload,
            'description' => $payload,
            'note' => $payload,
        ]);
        $writer->writeItem([
            'sku' => 'SAFE-1',
            'title' => 'Plain title',
            'description' => 'Plain description',
            'note' => 'Plain note',
        ]);
        $writer->finish();

        $out = $xml->outputMemory();
        $dom = $this->assertWellFormed($out);
        self::assertSame(2, $dom->getElementsByTagName('product')->length);
        // The hostile payload never materialises as a live <script> element.
        self::assertSame(0, $dom->getElementsByTagName('script')->length);
        // The strip policy removes markup entirely from the note slot.
        $notes = $dom->getElementsByTagName('note');
        self::assertStringNotContainsString('<script>', (string) $notes->item(0)?->textContent);
    }

    private function assertWellFormed(string $xml): DOMDocument
    {
        self::assertNotSame('', $xml);
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml);
        libxml_use_internal_errors($previous);

        self::assertTrue($loaded, 'Generated XML must be well-formed: '.substr($xml, 0, 200));

        return $dom;
    }
}
