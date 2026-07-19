<?php

declare(strict_types=1);

namespace App\Tests\Unit\Integration\Generic\Infrastructure\Http\Pagination;

use App\Integration\Generic\Domain\Entity\Connection;
use App\Integration\Generic\Domain\GenericRestResponse;
use App\Integration\Generic\Infrastructure\Http\RemoteRequester;

/**
 * Test double for {@see RemoteRequester}: replays a queue of canned responses
 * and records each call's URL + query so pagination assertions can inspect how
 * the walk drove the requests. When the queue drains it returns `$default`
 * (or an empty page) — handy for the loop-guard case where every page is full.
 */
final class RecordingRequester implements RemoteRequester
{
    /** @var list<array{url: string, method: string, query: array<string, string|int>, headers: array<string, string>, body: ?string}> */
    public array $calls = [];

    /**
     * @param list<GenericRestResponse> $queue
     */
    public function __construct(
        private array $queue = [],
        private ?GenericRestResponse $default = null,
    ) {
    }

    public function request(
        Connection $connection,
        string $method,
        string $url,
        array $query = [],
        array $headers = [],
        ?string $body = null,
    ): GenericRestResponse {
        $this->calls[] = ['url' => $url, 'method' => $method, 'query' => $query, 'headers' => $headers, 'body' => $body];

        return array_shift($this->queue)
            ?? $this->default
            ?? new GenericRestResponse(200, [], '[]', 1, 2);
    }
}
