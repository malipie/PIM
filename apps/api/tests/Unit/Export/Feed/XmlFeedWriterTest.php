<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Feed;

use App\Export\Feed\Domain\Descriptor\FeedDescriptor;
use App\Export\Feed\Infrastructure\Writer\XmlFeedWriter;
use App\Export\Infrastructure\Writer\XmlWriterCore;
use DOMDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * XMLF-P2-05 — XmlFeedWriter assembles descriptor-driven feed XML: element /
 * attribute / repeatable / wrapIn nodes, namespaces, header and CDATA, always
 * well-formed via XmlWriterCore.
 */
final class XmlFeedWriterTest extends TestCase
{
    private static function dom(string $xml): DOMDocument
    {
        $dom = new DOMDocument();
        self::assertNotFalse($dom->loadXML($xml), 'feed must be well-formed');

        return $dom;
    }

    #[Test]
    public function writesRssShapeWithNamespaceHeaderAndCdata(): void
    {
        $descriptor = FeedDescriptor::fromArray([
            'root' => ['element' => 'rss', 'attributes' => ['version' => '2.0'], 'namespaces' => ['g' => 'http://base.google.com/ns/1.0']],
            'channel' => [
                'element' => 'channel',
                'header' => [['element' => 'title', 'source' => ['kind' => 'static', 'value' => '{feed_name}']]],
                'item' => [
                    'element' => 'item',
                    'slots' => [
                        ['slot' => 'g:id', 'node' => 'element', 'fmt' => 'text'],
                        ['slot' => 'g:price', 'node' => 'element', 'fmt' => 'price'],
                        ['slot' => 'g:description', 'node' => 'element', 'html' => 'cdata', 'fmt' => 'html'],
                    ],
                ],
            ],
        ]);

        $xml = XmlWriterCore::toMemory();
        $writer = new XmlFeedWriter($xml, $descriptor, ['feed_name' => 'Klimas Feed']);
        $writer->begin();
        $writer->writeItem(['g:id' => 'KL-CB-4838', 'g:price' => '19.90 PLN', 'g:description' => '<p>opis & więcej</p>']);
        $writer->finish();
        $out = $xml->outputMemory();

        $dom = self::dom($out);
        self::assertStringContainsString('xmlns:g="http://base.google.com/ns/1.0"', $out);
        self::assertStringContainsString('<title>Klimas Feed</title>', $out);
        self::assertStringContainsString('<![CDATA[<p>opis & więcej</p>]]>', $out);

        $ids = $dom->getElementsByTagName('id'); // local name of g:id
        self::assertSame('KL-CB-4838', $ids->item(0)?->textContent);
    }

    #[Test]
    public function writesFlatShapeWithAttributesAndRepeatableWrap(): void
    {
        $descriptor = FeedDescriptor::fromArray([
            'root' => ['element' => 'offers', 'attributes' => ['version' => '1']],
            'item' => [
                'element' => 'o',
                'slots' => [
                    ['target' => 'id', 'node' => 'attribute', 'parent' => 'o', 'element' => 'id', 'fmt' => 'text'],
                    ['target' => 'price', 'node' => 'attribute', 'parent' => 'o', 'element' => 'price', 'fmt' => 'number'],
                    ['target' => 'name', 'node' => 'element', 'element' => 'name', 'fmt' => 'text'],
                    ['target' => 'img', 'node' => 'repeatable', 'element' => 'i', 'wrapIn' => 'imgs', 'fmt' => 'url'],
                ],
            ],
        ]);

        $xml = XmlWriterCore::toMemory();
        $writer = new XmlFeedWriter($xml, $descriptor);
        $writer->begin();
        $writer->writeItem([
            'id' => 'KL-1',
            'price' => '19.90',
            'name' => 'Wkręt',
            'img' => 'https://cdn/a.jpg|https://cdn/b.jpg',
        ]);
        $writer->finish();
        $out = $xml->outputMemory();

        $dom = self::dom($out);
        $o = $dom->getElementsByTagName('o')->item(0);
        self::assertNotNull($o);
        self::assertSame('KL-1', $o->getAttribute('id'));
        self::assertSame('19.90', $o->getAttribute('price'));
        self::assertSame('Wkręt', $dom->getElementsByTagName('name')->item(0)?->textContent);

        $imgs = $dom->getElementsByTagName('imgs')->item(0);
        self::assertNotNull($imgs);
        self::assertSame(2, $dom->getElementsByTagName('i')->length);
    }
}
