<?php

declare(strict_types=1);

namespace App\Integration\Generic\Application\Sync;

use InvalidArgumentException;

use const JSON_THROW_ON_ERROR;
use const PHP_QUERY_RFC1738;

/**
 * Encodes an outbound push body per the endpoint's request format (#2634).
 *
 * `json` is the raw-JSON wire shape used since APIC-P3-06 (now with an explicit
 * Content-Type). `form` targets RPC-style APIs — BaseLinker's connector.php
 * expects `application/x-www-form-urlencoded` where each top-level field is a
 * form parameter and nested objects travel as JSON strings
 * (`method=addInventoryProduct&parameters={"sku":…}`), so array values are
 * JSON-encoded before the query-string build.
 */
final readonly class OutboundBodyEncoder
{
    public const string FORMAT_JSON = 'json';
    public const string FORMAT_FORM = 'form';

    /**
     * @param array<string, mixed> $body
     */
    public function encode(array $body, string $format): EncodedBody
    {
        return match ($format) {
            self::FORMAT_JSON => new EncodedBody(
                json_encode($body, JSON_THROW_ON_ERROR),
                ['Content-Type' => 'application/json'],
            ),
            self::FORMAT_FORM => new EncodedBody(
                http_build_query(
                    array_map(
                        static fn (mixed $value): mixed => \is_array($value)
                            ? json_encode($value, JSON_THROW_ON_ERROR)
                            : $value,
                        $body,
                    ),
                    '',
                    '&',
                    PHP_QUERY_RFC1738,
                ),
                ['Content-Type' => 'application/x-www-form-urlencoded'],
            ),
            default => throw new InvalidArgumentException(\sprintf('Unknown request format "%s".', $format)),
        };
    }
}
