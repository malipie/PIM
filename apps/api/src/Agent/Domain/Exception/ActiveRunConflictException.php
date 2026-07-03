<?php

declare(strict_types=1);

namespace App\Agent\Domain\Exception;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * AGENT-P1-03 (#1955) — one active run per user (PRD 14.2 decision 3).
 * Renders as a 409 problem document via the shared RFC 7807 listener.
 */
final class ActiveRunConflictException extends RuntimeException implements HttpExceptionInterface
{
    public static function forUser(): self
    {
        return new self('You already have an active agent run. Wait for it to finish (or cancel it) before starting another.');
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
