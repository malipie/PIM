<?php

declare(strict_types=1);

namespace App\Integration\Generic\Application\Sync;

/**
 * A wire-ready outbound request body: the encoded payload plus the headers the
 * chosen format requires ({@see OutboundBodyEncoder}, #2634).
 */
final readonly class EncodedBody
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $body,
        public array $headers,
    ) {
    }
}
