<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Observability;

use App\Shared\Infrastructure\Observability\CorrelationIdContext;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class CorrelationIdContextTest extends TestCase
{
    public function testAcceptsSafeExternalIdAndKeepsItStable(): void
    {
        $context = new CorrelationIdContext();

        self::assertSame('client.trace_42:part-7', $context->initialize('client.trace_42:part-7'));
        self::assertSame('client.trace_42:part-7', $context->getOrCreate());
    }

    #[DataProvider('invalidExternalIds')]
    public function testReplacesUnsafeExternalId(string $externalId): void
    {
        $context = new CorrelationIdContext();

        $generated = $context->initialize($externalId);

        self::assertNotSame($externalId, $generated);
        self::assertTrue(Uuid::isValid($generated));
        self::assertTrue(CorrelationIdContext::isValid($generated));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidExternalIds(): iterable
    {
        yield 'empty' => [''];
        yield 'leading separator' => ['-request'];
        yield 'space' => ['request id'];
        yield 'line break' => ["request\nforged"];
        yield 'unicode' => ['żądzanie'];
        yield 'too long' => [str_repeat('a', CorrelationIdContext::MAX_LENGTH + 1)];
    }

    public function testRejectsUnsafeInternalBinding(): void
    {
        $context = new CorrelationIdContext();

        $this->expectException(InvalidArgumentException::class);

        $context->set("unsafe\r\nid");
    }

    public function testResetClearsLongLivedWorkerState(): void
    {
        $context = new CorrelationIdContext();
        $context->initialize('request-42');

        $context->reset();

        self::assertNull($context->get());
    }
}
