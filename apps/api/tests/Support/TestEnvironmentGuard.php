<?php

declare(strict_types=1);

namespace App\Tests\Support;

use RuntimeException;

final class TestEnvironmentGuard
{
    public static function assertSafe(?string $processEnvironment): void
    {
        if ('test' === $processEnvironment) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to start PHPUnit with process APP_ENV=%s. Use "composer test" so Foundry cannot reset the development database.',
            null === $processEnvironment ? '<unset>' : sprintf('"%s"', $processEnvironment),
        ));
    }
}
