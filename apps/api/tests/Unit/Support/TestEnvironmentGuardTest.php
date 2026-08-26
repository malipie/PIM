<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Tests\Support\TestEnvironmentGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TestEnvironmentGuardTest extends TestCase
{
    public function testAcceptsTestProcessEnvironment(): void
    {
        TestEnvironmentGuard::assertSafe('test');

        self::addToAssertionCount(1);
    }

    #[DataProvider('unsafeEnvironments')]
    public function testRejectsUnsafeProcessEnvironment(?string $environment, string $display): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('process APP_ENV=%s', $display));
        $this->expectExceptionMessage('Use "composer test"');

        TestEnvironmentGuard::assertSafe($environment);
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function unsafeEnvironments(): iterable
    {
        yield 'development' => ['dev', '"dev"'];
        yield 'production' => ['prod', '"prod"'];
        yield 'unset' => [null, '<unset>'];
    }
}
