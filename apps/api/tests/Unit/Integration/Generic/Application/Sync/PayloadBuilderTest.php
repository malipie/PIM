<?php

declare(strict_types=1);

namespace App\Tests\Unit\Integration\Generic\Application\Sync;

use App\Integration\Generic\Application\Sync\PayloadBuilder;
use App\Integration\Generic\Domain\Entity\Connection;
use App\Integration\Generic\Domain\Entity\FieldMapping;
use App\Integration\Generic\Domain\Enum\MappingDirection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PayloadBuilderTest extends TestCase
{
    private PayloadBuilder $builder;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->builder = new PayloadBuilder();
        $this->connection = new Connection('idosell', 'IdoSell', 'https://api.idosell.com');
    }

    #[Test]
    public function buildsNestedBodyFromOutboundMappings(): void
    {
        $mappings = [
            $this->mapping('sku', '$.sku', MappingDirection::Outbound),
            $this->mapping('name', '$.title', MappingDirection::Both),
            $this->mapping('price', '$.price.amount', MappingDirection::Outbound),
        ];
        $values = ['sku' => 'A-1', 'name' => 'Widget', 'price' => '1999'];

        $body = $this->builder->build($values, $mappings);

        self::assertSame([
            'sku' => 'A-1',
            'title' => 'Widget',
            'price' => ['amount' => 1999], // numeric literal coerced to a JSON number
        ], $body);
    }

    #[Test]
    public function coercesNumericValuesToJsonNumbers(): void
    {
        $mappings = [
            $this->mapping('productId', '$.params.products.0.productId', MappingDirection::Outbound),
            $this->mapping('price', '$.params.products.0.productRetailPrice', MappingDirection::Outbound),
            $this->mapping('sku', '$.params.products.0.productIndex', MappingDirection::Outbound),
            $this->mapping('zip', '$.params.products.0.zip', MappingDirection::Outbound),
            $this->mapping('lang', '$.params.products.0.langId', MappingDirection::Outbound),
        ];
        $values = [
            'productId' => '6635',      // → int
            'price' => '79.99',         // → float
            'sku' => 'SEM-23-777',      // non-numeric → string
            'zip' => '00123',           // leading zero → string
            'lang' => 'pol',            // → string
        ];

        $body = $this->builder->build($values, $mappings);

        self::assertSame([
            'params' => [
                'products' => [
                    [
                        'productId' => 6635,            // int
                        'productRetailPrice' => 79.99,  // float
                        'productIndex' => 'SEM-23-777', // non-numeric → string
                        'zip' => '00123',               // leading zero → string
                        'langId' => 'pol',              // → string
                    ],
                ],
            ],
        ], $body);
    }

    #[Test]
    public function ignoresInboundOnlyMappingsAndMissingValues(): void
    {
        $mappings = [
            $this->mapping('sku', '$.sku', MappingDirection::Outbound),
            $this->mapping('secret', '$.secret', MappingDirection::Inbound), // inbound-only → ignored
            $this->mapping('gone', '$.gone', MappingDirection::Outbound),     // no value → skipped
        ];
        $values = ['sku' => 'A-1', 'secret' => 'x'];

        $body = $this->builder->build($values, $mappings);

        self::assertSame(['sku' => 'A-1'], $body);
    }

    #[Test]
    public function bracketPathBuildsNestedObjectNotLiteralKey(): void
    {
        // #2642 — the BaseLinker price-group case: `prices['1374']` must nest,
        // not become a literal `prices['1374']` key the remote silently drops.
        $mappings = [
            $this->mapping('price', "\$.parameters.prices['1374']", MappingDirection::Outbound),
        ];

        $body = $this->builder->build(['price' => '249.99'], $mappings);

        self::assertSame(['parameters' => ['prices' => ['1374' => 249.99]]], $body);
        self::assertSame('{"parameters":{"prices":{"1374":249.99}}}', json_encode($body));
    }

    private function mapping(string $pimTarget, string $remotePath, MappingDirection $direction): FieldMapping
    {
        return new FieldMapping($this->connection, $pimTarget, $remotePath, $direction);
    }
}
