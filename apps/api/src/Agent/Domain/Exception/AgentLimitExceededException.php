<?php

declare(strict_types=1);

namespace App\Agent\Domain\Exception;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * AGENT-P1-04 (#1956) — a hard usage limit (architecture 8.5) was hit.
 * Renders as a 429 problem document via the shared RFC 7807 listener;
 * daily/monthly windows reset at midnight UTC (the kill-switch).
 */
final class AgentLimitExceededException extends RuntimeException implements HttpExceptionInterface
{
    public static function toolCallsPerHour(int $limit): self
    {
        return new self(\sprintf('Agent limit reached: %d tool calls per hour per user. Try again later.', $limit));
    }

    public static function tokensPerDay(int $limit): self
    {
        return new self(\sprintf('Agent limit reached: %d tokens per day per user. The agent is disabled until midnight UTC.', $limit));
    }

    public static function costPerDay(float $limitUsd): self
    {
        return new self(\sprintf('Agent limit reached: $%.2f per day per tenant. The agent is disabled until midnight UTC.', $limitUsd));
    }

    public static function costPerMonth(float $limitUsd): self
    {
        return new self(\sprintf('Agent limit reached: $%.2f per month per tenant. The agent is disabled until the end of the month (UTC).', $limitUsd));
    }

    public function getStatusCode(): int
    {
        return 429;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }
}
