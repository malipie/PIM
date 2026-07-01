<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Feed;

use App\Export\Feed\Application\Descriptor\FeedDescriptorGuard;
use App\Export\Feed\Domain\Descriptor\InvalidDescriptorException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * XMLF-P1-04 — the persist-time descriptor guard accepts a well-formed
 * descriptor and rejects structurally broken payloads early.
 */
final class FeedDescriptorGuardTest extends TestCase
{
    /** @return array<string, mixed> */
    private function validDescriptor(): array
    {
        return [
            'root' => ['element' => 'products'],
            'item' => [
                'element' => 'product',
                'slots' => [
                    ['slot' => 'sku', 'node' => 'element', 'required' => true, 'fmt' => 'text'],
                    ['slot' => 'name', 'node' => 'element', 'fmt' => 'text'],
                ],
            ],
        ];
    }

    #[Test]
    public function acceptsWellFormedDescriptor(): void
    {
        $descriptor = new FeedDescriptorGuard()->assertValid($this->validDescriptor());

        self::assertSame('products', $descriptor->rootElement);
        self::assertCount(2, $descriptor->slots);
        self::assertTrue(new FeedDescriptorGuard()->isValid($this->validDescriptor()));
    }

    #[Test]
    public function rejectsEmptyDescriptor(): void
    {
        $this->expectException(InvalidDescriptorException::class);
        new FeedDescriptorGuard()->assertValid([]);
    }

    #[Test]
    public function rejectsDescriptorWithoutRoot(): void
    {
        $this->expectException(InvalidDescriptorException::class);
        new FeedDescriptorGuard()->assertValid(['item' => ['element' => 'product', 'slots' => []]]);
    }

    #[Test]
    public function rejectsDescriptorWithoutItem(): void
    {
        $this->expectException(InvalidDescriptorException::class);
        new FeedDescriptorGuard()->assertValid(['root' => ['element' => 'rss']]);
    }

    #[Test]
    public function delegatesDeepValidationToValueObject(): void
    {
        // Top-level shape is fine, but a slot has an illegal XML element name —
        // only the value object catches that, proving the guard delegates.
        $descriptor = [
            'root' => ['element' => 'products'],
            'item' => [
                'element' => 'product',
                'slots' => [['slot' => 'bad.name', 'node' => 'element', 'fmt' => 'text']],
            ],
        ];

        $this->expectException(InvalidDescriptorException::class);
        new FeedDescriptorGuard()->assertValid($descriptor);
    }
}
