<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability;

use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Request/message-scoped correlation id for long-lived HTTP and Messenger workers.
 */
final class CorrelationIdContext implements ResetInterface
{
    public const HEADER_NAME = 'X-Request-ID';
    public const LOG_FIELD = 'correlation_id';
    public const MAX_LENGTH = 128;

    private ?string $correlationId = null;

    public function initialize(?string $externalId): string
    {
        $correlationId = null !== $externalId && self::isValid($externalId)
            ? $externalId
            : self::generate();
        $this->correlationId = $correlationId;

        return $correlationId;
    }

    public function get(): ?string
    {
        return $this->correlationId;
    }

    public function getOrCreate(): string
    {
        return $this->correlationId ??= self::generate();
    }

    public function set(string $correlationId): void
    {
        if (!self::isValid($correlationId)) {
            throw new InvalidArgumentException('The correlation id contains unsafe characters or has an invalid length.');
        }

        $this->correlationId = $correlationId;
    }

    public function reset(): void
    {
        $this->correlationId = null;
    }

    public static function isValid(?string $correlationId): bool
    {
        if (null === $correlationId || '' === $correlationId || self::MAX_LENGTH < \strlen($correlationId)) {
            return false;
        }

        return 1 === preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/D', $correlationId);
    }

    private static function generate(): string
    {
        return Uuid::v7()->toRfc4122();
    }
}
