<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/** Adds the active request/message correlation id to every Monolog record. */
final readonly class CorrelationIdLogProcessor implements ProcessorInterface
{
    public function __construct(
        private CorrelationIdContext $context,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $correlationId = $this->context->get();
        if (null === $correlationId) {
            return $record;
        }

        return $record->with(extra: [
            ...$record->extra,
            CorrelationIdContext::LOG_FIELD => $correlationId,
        ]);
    }
}
