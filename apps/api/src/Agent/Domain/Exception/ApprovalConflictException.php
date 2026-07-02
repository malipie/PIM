<?php

declare(strict_types=1);

namespace App\Agent\Domain\Exception;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * AGENT-P3-02 (#1962) — approve is only legal on a run that is
 * awaiting_approval with a materialized batch. Renders as a 409 problem
 * document via the shared RFC 7807 listener.
 */
final class ApprovalConflictException extends RuntimeException implements HttpExceptionInterface
{
    public static function wrongStatus(string $status): self
    {
        return new self(\sprintf('Only a run awaiting approval can be approved; this run is "%s".', $status));
    }

    public static function noBatch(): self
    {
        return new self('This run has no materialized pending-change batch to approve.');
    }

    public static function nothingToCommit(): self
    {
        return new self('The pending batch had no accepted rows to commit (already decided or expired).');
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
