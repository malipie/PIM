<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Application\BulkContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BulkContextTest extends TestCase
{
    #[Test]
    public function defaultsToFalse(): void
    {
        $context = new BulkContext();
        self::assertFalse($context->isBulk());
    }

    #[Test]
    public function setBulkTogglesFlag(): void
    {
        $context = new BulkContext();

        $context->setBulk(true);
        self::assertTrue($context->isBulk());

        $context->setBulk(false);
        self::assertFalse($context->isBulk());
    }

    #[Test]
    public function scopeRestoresTheFlagAfterFailure(): void
    {
        $context = new BulkContext();
        $caught = false;

        try {
            $context->run(static function () use ($context): never {
                self::assertTrue($context->isBulk());

                throw new RuntimeException('stop');
            });
        } catch (RuntimeException $e) {
            $caught = true;
            self::assertSame('stop', $e->getMessage());
        }

        self::assertTrue($caught, 'The scoped operation should propagate its failure.');
        self::assertFalse($context->isBulk());
    }
}
