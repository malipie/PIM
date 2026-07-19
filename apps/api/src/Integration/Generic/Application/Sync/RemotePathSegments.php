<?php

declare(strict_types=1);

namespace App\Integration\Generic\Application\Sync;

use const PREG_SET_ORDER;

/**
 * The single field-path tokenizer of the generic connector (#2642).
 *
 * Splits `$.a.b`, `a.b['c.d']`, `$.prices[1374]` into segments, accepting the
 * bracket dialect of JSONPath (`['key']` / `["key"]` / `[1374]`) alongside the
 * dot notation. Operators reach for brackets naturally for numeric keys
 * (BaseLinker price-group ids) — the previous dot-only split turned
 * `prices['1374']` into a literal key the remote silently ignored. A quoted
 * key may contain dots; wildcards/filters are NOT supported (same as before).
 * Root (`$`, ``, null) yields no segments.
 */
final readonly class RemotePathSegments
{
    private const string TOKEN_PATTERN = <<<'REGEX'
        /\['([^']*)'\]|\["([^"]*)"\]|\[([^\]]+)\]|([^.\[\]]+)/
        REGEX;

    /**
     * @return list<string>
     */
    public static function parse(?string $path): array
    {
        if (null === $path) {
            return [];
        }

        $trimmed = ltrim(ltrim(trim($path), '$'), '.');
        if ('' === $trimmed) {
            return [];
        }

        preg_match_all(self::TOKEN_PATTERN, $trimmed, $matches, PREG_SET_ORDER);

        $segments = [];
        foreach ($matches as $match) {
            // First non-empty capture group wins: 'key' / "key" / bare / plain.
            foreach ([1, 2, 3, 4] as $group) {
                if ('' !== ($match[$group] ?? '')) {
                    $segments[] = $match[$group];
                    break;
                }
            }
        }

        return $segments;
    }
}
