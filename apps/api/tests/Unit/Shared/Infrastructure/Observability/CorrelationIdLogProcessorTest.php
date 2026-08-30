<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Observability;

use App\Shared\Infrastructure\Observability\CorrelationIdContext;
use App\Shared\Infrastructure\Observability\CorrelationIdLogProcessor;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class CorrelationIdLogProcessorTest extends TestCase
{
    public function testAddsCorrelationIdWithoutDroppingExistingExtraFields(): void
    {
        $context = new CorrelationIdContext();
        $context->set('request-42');
        $processor = new CorrelationIdLogProcessor($context);
        $record = new LogRecord(
            new DateTimeImmutable(),
            'app',
            Level::Info,
            'Import queued',
            extra: ['worker' => 'import'],
        );

        $processed = $processor($record);

        self::assertSame('request-42', $processed->extra[CorrelationIdContext::LOG_FIELD]);
        self::assertSame('import', $processed->extra['worker']);
    }

    public function testLeavesRecordUnchangedOutsideCorrelatedFlow(): void
    {
        $processor = new CorrelationIdLogProcessor(new CorrelationIdContext());
        $record = new LogRecord(new DateTimeImmutable(), 'app', Level::Info, 'Scheduler booted');

        self::assertSame($record, $processor($record));
    }
}
