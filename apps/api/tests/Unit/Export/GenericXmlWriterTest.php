<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export;

use App\Export\Application\Builder\ColumnDefinition;
use App\Export\Infrastructure\Writer\GenericXmlWriter;
use App\Export\Infrastructure\Writer\XmlWriterCore;
use DOMDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * XMLF-P0-04 — GenericXmlWriter emits the ad-hoc `<products><product>` dump:
 * one element per column, `.locale`/`.channel` scope as attributes, always
 * well-formed (via XmlWriterCore).
 */
final class GenericXmlWriterTest extends TestCase
{
    /**
     * @param list<ColumnDefinition> $columns
     * @param array<string, string>  $item
     */
    private static function render(array $columns, array $item): string
    {
        $xml = XmlWriterCore::toMemory();
        $writer = new GenericXmlWriter($xml, $columns);
        $writer->begin();
        $writer->writeItem($item);
        $writer->finish();

        return $xml->outputMemory();
    }

    private static function dom(string $xml): DOMDocument
    {
        $dom = new DOMDocument();
        self::assertNotFalse($dom->loadXML($xml), 'output must be well-formed');

        return $dom;
    }

    #[Test]
    public function wrapsItemsAndMapsColumnsToElements(): void
    {
        $columns = [
            new ColumnDefinition('sku', ColumnDefinition::KIND_BUILT_IN, 'sku'),
            new ColumnDefinition('description.pl', ColumnDefinition::KIND_ATTRIBUTE, 'description', 'pl'),
            new ColumnDefinition('price.shopify', ColumnDefinition::KIND_ATTRIBUTE, 'price', null, 'shopify'),
        ];

        $xml = self::render($columns, [
            'sku' => 'KL-CB-4838',
            'description.pl' => 'Wkręt ciesielski',
            'price.shopify' => '19.90 PLN',
        ]);

        $dom = self::dom($xml);
        self::assertSame(1, $dom->getElementsByTagName('products')->length);
        self::assertSame(1, $dom->getElementsByTagName('product')->length);

        $desc = $dom->getElementsByTagName('description')->item(0);
        self::assertNotNull($desc);
        self::assertSame('pl', $desc->getAttribute('locale'));
        self::assertSame('Wkręt ciesielski', $desc->textContent);

        $price = $dom->getElementsByTagName('price')->item(0);
        self::assertNotNull($price);
        self::assertSame('shopify', $price->getAttribute('channel'));
        self::assertSame('19.90 PLN', $price->textContent);
    }

    #[Test]
    public function escapesHostileValuesAndStaysWellFormed(): void
    {
        $columns = [new ColumnDefinition('name', ColumnDefinition::KIND_BUILT_IN, 'name')];

        $xml = self::render($columns, ['name' => 'A & B <script> ]]> "x"']);

        self::assertStringNotContainsString('<script>', $xml);
        $dom = self::dom($xml);
        $name = $dom->getElementsByTagName('name')->item(0);
        self::assertNotNull($name);
        self::assertSame('A & B <script> ]]> "x"', $name->textContent);
    }

    #[Test]
    public function missingCellRendersEmptyElement(): void
    {
        $columns = [
            new ColumnDefinition('sku', ColumnDefinition::KIND_BUILT_IN, 'sku'),
            new ColumnDefinition('ean', ColumnDefinition::KIND_ATTRIBUTE, 'ean'),
        ];

        $xml = self::render($columns, ['sku' => 'KL-1']); // no 'ean' key

        $dom = self::dom($xml);
        $ean = $dom->getElementsByTagName('ean')->item(0);
        self::assertNotNull($ean);
        self::assertSame('', $ean->textContent);
    }

    #[Test]
    public function sanitisesIllegalElementNameFromCode(): void
    {
        // A code with characters illegal in an XML element name.
        $columns = [new ColumnDefinition('weird', ColumnDefinition::KIND_ATTRIBUTE, '1 bad:name')];

        $xml = self::render($columns, ['weird' => 'v']);

        $dom = self::dom($xml); // must not throw / must be well-formed
        // Leading digit gets an underscore prefix; illegal chars become '_'.
        self::assertMatchesRegularExpression('/<_1_bad_name>v<\/_1_bad_name>/', $xml);
        self::assertNotNull($dom->documentElement);
    }
}
