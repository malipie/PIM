<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Catalog;

use App\Export\Catalog\Domain\Mapping\CatalogFieldMapping;
use App\Export\Catalog\Domain\Mapping\CatalogItemMapper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CPDF-P2-02 — the pure slot mapper resolves attribute / static / template
 * sources into slot => value, feeding catalog template slots from ExportBuilder
 * attribute maps. Transforms are a deferred hook (plan §7) — no transform path.
 */
final class CatalogItemMapperTest extends TestCase
{
    #[Test]
    public function mapsAttributeStaticAndTemplateSources(): void
    {
        $mappings = [
            new CatalogFieldMapping('title', 'attribute', 'name', null),
            new CatalogFieldMapping('brand', 'static', null, 'ACME'),
            new CatalogFieldMapping('label', 'template', null, '{name} ({sku})'),
        ];

        $item = new CatalogItemMapper()->map($mappings, ['sku' => 'SKU-1', 'name' => 'Widget']);

        self::assertSame('Widget', $item['title']);
        self::assertSame('ACME', $item['brand']);
        self::assertSame('Widget (SKU-1)', $item['label']);
    }

    #[Test]
    public function unknownAttributeResolvesToEmptyString(): void
    {
        $mappings = [
            new CatalogFieldMapping('title', 'attribute', 'ghost', null),
            new CatalogFieldMapping('note', 'static', null, null),
            new CatalogFieldMapping('other', 'unknown_kind', 'name', null),
        ];

        $item = new CatalogItemMapper()->map($mappings, ['name' => 'Widget']);

        self::assertSame('', $item['title']);
        self::assertSame('', $item['note']);
        self::assertSame('', $item['other']);
    }

    #[Test]
    public function mapsAListParsedFromArray(): void
    {
        $mappings = CatalogFieldMapping::listFromArray([
            ['slot' => 'id', 'source' => ['kind' => 'attribute', 'ref' => 'sku']],
            ['slot' => 'name', 'source' => ['kind' => 'attribute', 'ref' => 'name']],
            ['slot' => 'currency', 'source' => ['kind' => 'static', 'value' => 'PLN']],
            'not-an-array-is-skipped',
        ]);

        self::assertCount(3, $mappings);

        $item = new CatalogItemMapper()->map($mappings, ['sku' => 'SKU-1', 'name' => 'Widget']);

        self::assertSame(['id' => 'SKU-1', 'name' => 'Widget', 'currency' => 'PLN'], $item);
    }
}
