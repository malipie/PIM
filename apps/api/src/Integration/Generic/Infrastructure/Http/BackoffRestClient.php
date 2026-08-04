<?php

declare(strict_types=1);

namespace App\Integration\Generic\Infrastructure\Http;

use App\Integration\Generic\Application\Sleeper;
use App\Integration\Generic\Domain\Entity\Connection;
use App\Integration\Generic\Domain\GenericRestResponse;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Throttle-aware wrapper over {@see GenericRestClient} (APIC-P1-09, ADR-0022).
 *
 * Per CLAUDE.md §Throttling, exponential backoff is the ONLY rate-limiting
 * mechanism in MVP — no leaky bucket, no shared Redis bucket state. On a 429
 * (or 503) the wrapper waits `Retry-After` seconds (falling back to 2^attempt,
 * capped at 60s) and retries, up to a small attempt budget; the final response
 * is returned for the caller (sync runner) to dead-letter if still throttled.
 *
 * The per-tenant anti-abuse budget is enforced separately at the sync-trigger
 * edge — the `integration_sync` limiter (10/h/tenant), consumed by
 * {@see \App\Integration\Generic\Application\Subscriber\OutboundTriggerSubscriber}
 * since #2730 (before that the limiter was configured but nothing consumed it,
 * and this paragraph described a control that did not exist). Deliberately not
 * per HTTP call — a per-call bucket would starve legitimate bulk syncs.
 */
final readonly class BackoffRestClient implements RemoteRequester
{
    private const int MAX_ATTEMPTS = 5;
    private const int MAX_BACKOFF_SECONDS = 60;

    /** @var list<int> HTTP statuses that warrant a backoff + retry. */
    private const array RETRYABLE_STATUSES = [429, 503];

    public function __construct(
        private GenericRestClient $client,
        private Sleeper $sleeper,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param array<string, string|int> $query
     * @param array<string, string>     $headers
     */
    public function request(
        Connection $connection,
        string $method,
        string $url,
        array $query = [],
        array $headers = [],
        ?string $body = null,
    ): GenericRestResponse {
        $attempt = 0;
        while (true) {
            $response = $this->client->request($connection, $method, $url, $query, $headers, $body);

            $isLastAttempt = $attempt >= self::MAX_ATTEMPTS - 1;
            if ($isLastAttempt || !\in_array($response->statusCode, self::RETRYABLE_STATUSES, true)) {
                return $response;
            }

            $delay = $this->backoffSeconds($response, $attempt);
            $this->logger->info('External connection throttled; backing off.', [
                'connection' => $connection->getCode(),
                'status' => $response->statusCode,
                'attempt' => $attempt + 1,
                'delay_seconds' => $delay,
            ]);
            $this->sleeper->sleep($delay);
            ++$attempt;
        }
    }

    private function backoffSeconds(GenericRestResponse $response, int $attempt): int
    {
        $delay = $response->retryAfterSeconds() ?? (1 << $attempt);

        return min($delay, self::MAX_BACKOFF_SECONDS);
    }
}
