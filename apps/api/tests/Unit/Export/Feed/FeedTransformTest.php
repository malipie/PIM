<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Feed;

use App\Export\Feed\Domain\Mapping\FeedFieldMapping;
use App\Export\Feed\Domain\Mapping\FeedItemMapper;
use App\Export\Feed\Domain\Mapping\FeedTransformApplier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * XMLF-P2-03 — the closed transform list + item mapper.
 */
final class FeedTransformTest extends TestCase
{
    private FeedTransformApplier $t;

    protected function setUp(): void
    {
        $this->t = new FeedTransformApplier();
    }

    #[Test]
    public function priceFormatsWithCurrency(): void
    {
        self::assertSame('19.90 PLN', $this->t->apply(['kind' => 'price'], '19.9', ['currency' => 'PLN']));
        self::assertSame('1234.50 EUR', $this->t->apply(['kind' => 'price'], '1 234,5', ['currency' => 'EUR']));
    }

    #[Test]
    public function numberFormatsPrecision(): void
    {
        self::assertSame('1234.50', $this->t->apply(['kind' => 'number', 'precision' => 2], '1234.5'));
        self::assertSame('7', $this->t->apply(['kind' => 'number', 'precision' => 0], '7.4'));
    }

    #[Test]
    public function defaultFillsEmptyOnly(): void
    {
        self::assertSame('Unbranded', $this->t->apply(['kind' => 'default', 'value' => 'Unbranded'], ''));
        self::assertSame('Klimas', $this->t->apply(['kind' => 'default', 'value' => 'Unbranded'], 'Klimas'));
    }

    #[Test]
    public function enumMapStockRule(): void
    {
        self::assertSame('in_stock', $this->t->apply(['kind' => 'enum_map', 'rule' => 'stock'], '15'));
        self::assertSame('out_of_stock', $this->t->apply(['kind' => 'enum_map', 'rule' => 'stock'], '0'));
        self::assertSame('in stock', $this->t->apply(['kind' => 'enum_map', 'rule' => 'meta_stock'], '3'));
    }

    #[Test]
    public function stripHtmlAndTruncate(): void
    {
        self::assertSame('opis produktu', $this->t->apply(['kind' => 'strip_html'], '<p>opis <b>produktu</b></p>'));
        self::assertSame('Wkręt ciesielski', $this->t->apply(['kind' => 'truncate', 'to' => 17], 'Wkręt ciesielski do drewna'));
    }

    #[Test]
    public function templateInterpolatesSiblingFields(): void
    {
        $out = $this->t->apply(['kind' => 'template', 'value' => '{name} {brand}'], '', ['name' => 'Wkręt', 'brand' => 'Klimas']);
        self::assertSame('Wkręt Klimas', $out);
    }

    #[Test]
    public function mapperResolvesSourcesAndTransforms(): void
    {
        $mapper = new FeedItemMapper($this->t);
        $mappings = FeedFieldMapping::listFromArray([
            ['slot' => 'g:id', 'source' => ['kind' => 'attribute', 'ref' => 'sku']],
            ['slot' => 'g:price', 'source' => ['kind' => 'attribute', 'ref' => 'price_gross'], 'transform' => ['kind' => 'price']],
            ['slot' => 'g:condition', 'source' => ['kind' => 'static', 'value' => 'new']],
            ['slot' => 'g:link', 'source' => ['kind' => 'template', 'value' => '{store_url}/p/{url_slug}']],
        ]);

        $item = $mapper->map(
            $mappings,
            ['sku' => 'KL-1', 'price_gross' => '19.9', 'url_slug' => 'wkret'],
            ['currency' => 'PLN', 'store_url' => 'https://shop.pl'],
        );

        self::assertSame('KL-1', $item['g:id']);
        self::assertSame('19.90 PLN', $item['g:price']);
        self::assertSame('new', $item['g:condition']);
        self::assertSame('https://shop.pl/p/wkret', $item['g:link']);
    }
}
