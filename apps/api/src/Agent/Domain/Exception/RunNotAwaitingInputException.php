<?php

declare(strict_types=1);

namespace App\Agent\Domain\Exception;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * AGENT-P4-01 (#1968) — a next user turn is only legal on a run that is
 * awaiting_input. Renders as a 409 problem document via the shared
 * RFC 7807 listener.
 */
final class RunNotAwaitingInputException extends RuntimeException implements HttpExceptionInterface
{
    public static function forStatus(string $status): self
    {
        return new self(\sprintf('Only a run awaiting input accepts a new message; this run is "%s".', $status));
    }

    public function getStatusCode(): int
    {
        return 409;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }
}
