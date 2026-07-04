<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Meilisearch;

/**
 * Canonical escaper for string literals interpolated into Meilisearch
 * filter expressions (#2225, deep-audit consistency finding).
 *
 * Every writer that builds a `key = "value"` clause quotes the value
 * through this helper so `"` and `\` inside user-supplied values stay part
 * of the literal instead of terminating it. `addslashes()` also neutralised
 * the double quote, but additionally escapes `'` and NUL — a shape the
 * Meilisearch filter grammar does not define. One shared escaper keeps the
 * audit surface single-sourced.
 */
final class MeiliFilterLiteral
{
    private function __construct()
    {
    }

    /**
     * Returns the value as a double-quoted Meilisearch string literal with
     * `\` and `"` escaped.
     */
    public static function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
