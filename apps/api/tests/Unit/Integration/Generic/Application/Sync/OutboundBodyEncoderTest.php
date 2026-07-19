<?php

declare(strict_types=1);

namespace App\Tests\Unit\Integration\Generic\Application\Sync;

use App\Integration\Generic\Application\Sync\OutboundBodyEncoder;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #2634 — per-endpoint outbound wire format: `json` stays the raw-JSON shape,
 * `form` produces the RPC envelope BaseLinker's connector.php expects
 * (form-urlencoded fields, nested objects as JSON strings).
 */
final class OutboundBodyEncoderTest extends TestCase
{
    #[Test]
    public function jsonFormatEncodesRawJsonWithContentType(): void
    {
        $encoded = new OutboundBodyEncoder()->encode(['sku' => 'A-1', 'price' => 9.99], 'json');

        self::assertSame('{"sku":"A-1","price":9.99}', $encoded->body);
        self::assertSame(['Content-Type' => 'application/json'], $encoded->headers);
    }

    #[Test]
    public function formFormatUrlencodesTopLevelAndJsonEncodesNestedObjects(): void
    {
        $encoded = new OutboundBodyEncoder()->encode([
            'method' => 'addInventoryProduct',
            'parameters' => ['inventory_id' => 1234, 'sku' => 'A-1'],
        ], 'form');

        parse_str($encoded->body, $fields);
        self::assertSame('addInventoryProduct', $fields['method']);
        self::assertIsString($fields['parameters']);
        self::assertSame(
            ['inventory_id' => 1234, 'sku' => 'A-1'],
            json_decode($fields['parameters'], true),
        );
        self::assertSame(['Content-Type' => 'application/x-www-form-urlencoded'], $encoded->headers);
    }

    #[Test]
    public function formFormatKeepsScalarsAsPlainFields(): void
    {
        $encoded = new OutboundBodyEncoder()->encode(['token' => 'abc', 'count' => 2], 'form');

        self::assertSame('token=abc&count=2', $encoded->body);
    }

    #[Test]
    public function unknownFormatThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new OutboundBodyEncoder()->encode(['a' => 1], 'xml');
    }
}
