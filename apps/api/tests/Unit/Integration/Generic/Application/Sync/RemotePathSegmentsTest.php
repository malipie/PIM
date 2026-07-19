<?php

declare(strict_types=1);

namespace App\Tests\Unit\Integration\Generic\Application\Sync;

use App\Integration\Generic\Application\Sync\RemotePathSegments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #2642 — the shared field-path tokenizer: dot notation stays byte-compatible,
 * bracket notation (`['key']` / `["key"]` / `[1374]`) becomes a first-class
 * segment instead of a literal key the remote silently drops.
 */
final class RemotePathSegmentsTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('paths')]
    #[Test]
    public function tokenizes(?string $path, array $expected): void
    {
        self::assertSame($expected, RemotePathSegments::parse($path));
    }

    /**
     * @return iterable<string, array{?string, list<string>}>
     */
    public static function paths(): iterable
    {
        // Roots and empties (unchanged contract).
        yield 'null' => [null, []];
        yield 'empty' => ['', []];
        yield 'root' => ['$', []];
        yield 'root dot' => ['$.', []];

        // Dot notation (unchanged contract).
        yield 'plain' => ['results', ['results']];
        yield 'dollar dot' => ['$.results', ['results']];
        yield 'nested' => ['$.data.items', ['data', 'items']];
        yield 'double dot collapses' => ['$.a..b', ['a', 'b']];
        yield 'numeric dot key' => ['$.parameters.prices.1374', ['parameters', 'prices', '1374']];

        // Bracket notation (#2642 — the BaseLinker price-group case).
        yield 'single-quoted bracket' => [
            "\$.parameters.prices['1374']",
            ['parameters', 'prices', '1374'],
        ];
        yield 'double-quoted bracket' => ['$.parameters.prices["1374"]', ['parameters', 'prices', '1374']];
        yield 'bare numeric bracket' => ['$.parameters.prices[1374]', ['parameters', 'prices', '1374']];
        yield 'bracket on root' => ["\$['results']", ['results']];
        yield 'quoted key with dot' => ["\$.a['b.c'].d", ['a', 'b.c', 'd']];
        yield 'chained brackets' => ["\$.a['b']['c']", ['a', 'b', 'c']];
        yield 'mixed' => ["a.b['c.d'][0].e", ['a', 'b', 'c.d', '0', 'e']];
    }
}
