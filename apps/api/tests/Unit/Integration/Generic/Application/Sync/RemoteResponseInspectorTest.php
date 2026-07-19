<?php

declare(strict_types=1);

namespace App\Tests\Unit\Integration\Generic\Application\Sync;

use App\Integration\Generic\Application\Sync\RemoteResponseInspector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #1886 — a remote 2xx body can still report a per-record error; the inspector
 * surfaces a generic error envelope so the sync does not count it as success.
 */
final class RemoteResponseInspectorTest extends TestCase
{
    #[DataProvider('bodies')]
    #[Test]
    public function detectsErrorEnvelopes(string $body, ?string $expected): void
    {
        self::assertSame($expected, RemoteResponseInspector::errorIn($body));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function bodies(): iterable
    {
        // Success / no error.
        yield 'empty body' => ['', null];
        yield 'non-json' => ['OK', null];
        yield 'empty object' => ['{}', null];
        yield 'idosell ok envelope' => ['{"errors":{"faultCode":0,"faultString":""}}', null];
        yield 'empty errors list' => ['{"errors":[]}', null];
        yield 'plain result' => ['{"results":[{"productId":6635}]}', null];

        // Errors.
        yield 'idosell fault string' => [
            '{"errors":{"faultCode":2,"faultString":"Invalid productRetailPrice"}}',
            'Invalid productRetailPrice',
        ];
        yield 'idosell fault code only' => ['{"errors":{"faultCode":7,"faultString":""}}', 'faultCode 7'];
        yield 'top-level error string' => ['{"error":"unauthorized"}', 'unauthorized'];
        yield 'errors list' => ['{"errors":["bad sku","bad price"]}', 'remote returned 2 error(s)'];

        // BaseLinker-style RPC status envelope (#2634).
        yield 'baselinker success' => ['{"status":"SUCCESS","product_id":123}', null];
        yield 'baselinker non-error status' => ['{"status":"draft"}', null];
        yield 'baselinker error message' => [
            '{"status":"ERROR","error_code":"ERROR_AUTH_TOKEN","error_message":"Invalid token"}',
            'Invalid token',
        ];
        yield 'baselinker error code only' => [
            '{"status":"ERROR","error_code":"ERROR_UNKNOWN_METHOD"}',
            'error_code ERROR_UNKNOWN_METHOD',
        ];
        yield 'baselinker bare error status' => ['{"status":"ERROR"}', 'remote returned status ERROR'];
    }
}
