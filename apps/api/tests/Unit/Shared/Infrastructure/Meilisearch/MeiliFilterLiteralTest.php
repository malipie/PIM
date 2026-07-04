<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Meilisearch;

use App\Shared\Infrastructure\Meilisearch\MeiliFilterLiteral;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #2225 — canonical Meilisearch string-literal escaper. The exact quoted
 * shapes below are the contract every filter-expression writer relies on:
 * a `"` or `\` inside a user-supplied value must stay part of the literal
 * instead of terminating it (and re-opening the expression for operators).
 */
final class MeiliFilterLiteralTest extends TestCase
{
    #[Test]
    public function quotesPlainValueVerbatim(): void
    {
        self::assertSame('"Festo"', MeiliFilterLiteral::quote('Festo'));
    }

    #[Test]
    public function escapesDoubleQuote(): void
    {
        self::assertSame('"a\\"b"', MeiliFilterLiteral::quote('a"b'));
    }

    #[Test]
    public function escapesBackslash(): void
    {
        self::assertSame('"a\\\\b"', MeiliFilterLiteral::quote('a\\b'));
    }

    #[Test]
    public function escapesBackslashBeforeQuoteWithoutDoubleProcessing(): void
    {
        // Input `a\"b` (backslash then quote): the backslash and the quote are
        // escaped independently — `a\\\"b` — never collapsed into a lone `\"`
        // that would leave the literal terminable.
        self::assertSame('"a\\\\\\"b"', MeiliFilterLiteral::quote('a\\"b'));
    }

    #[Test]
    public function neutralisesOrInjectionPayloadAsSingleLiteral(): void
    {
        $quoted = MeiliFilterLiteral::quote('zzz" OR enabled = "true');

        // The payload's quotes are escaped, so the whole value remains ONE
        // string literal — the `OR` never becomes an operator.
        self::assertSame('"zzz\\" OR enabled = \\"true"', $quoted);
    }
}
