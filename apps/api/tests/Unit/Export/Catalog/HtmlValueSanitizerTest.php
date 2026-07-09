<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Catalog;

use App\Export\Catalog\Application\HtmlValueSanitizer;
use App\Export\Catalog\Domain\HtmlSlotPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CPDF-P0-04 [SEC] — failing-test-first: garbage product values must never
 * break the catalog HTML or inject executable/layout-breaking content,
 * whatever the slot policy.
 */
final class HtmlValueSanitizerTest extends TestCase
{
    private HtmlValueSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new HtmlValueSanitizer();
    }

    public function testEscapePolicyLeavesNoLiveMarkup(): void
    {
        $out = $this->sanitizer->sanitize(
            'Kabel <script>alert(1)</script> <b>HDMI</b>',
            HtmlSlotPolicy::Escape,
        );

        // Everything becomes inert text: no unescaped '<' survives.
        self::assertStringNotContainsString('<', $out);
        self::assertStringContainsString('&lt;script&gt;', $out);
    }

    public function testRichTextDropsScriptButKeepsAllowedTags(): void
    {
        $out = $this->sanitizer->sanitize(
            '<p>Opis <strong>produktu</strong></p><script>alert(1)</script>',
            HtmlSlotPolicy::RichText,
        );

        self::assertStringNotContainsString('<script', $out);
        self::assertStringContainsString('<strong>', $out);
    }

    public function testRichTextBlocksJavascriptHref(): void
    {
        $out = $this->sanitizer->sanitize(
            '<a href="javascript:alert(1)">klik</a>',
            HtmlSlotPolicy::RichText,
        );

        self::assertStringNotContainsString('javascript:', $out);
        self::assertStringContainsString('klik', $out);
    }

    public function testRichTextDropsDisallowedTags(): void
    {
        $out = $this->sanitizer->sanitize(
            '<img src=x onerror="alert(1)"><iframe src="//evil"></iframe>ok',
            HtmlSlotPolicy::RichText,
        );

        self::assertStringNotContainsString('<img', $out);
        self::assertStringNotContainsString('<iframe', $out);
        self::assertStringNotContainsString('onerror', $out);
        self::assertStringContainsString('ok', $out);
    }

    public function testStripPolicyRemovesAllTags(): void
    {
        $out = $this->sanitizer->sanitize(
            '<div onerror="x"><b>tekst</b></div>',
            HtmlSlotPolicy::Strip,
        );

        self::assertStringNotContainsString('<', $out);
        self::assertStringNotContainsString('onerror', $out);
        self::assertStringContainsString('tekst', $out);
    }

    #[DataProvider('garbageProvider')]
    public function testGarbageStaysWellFormedAndInert(string $garbage): void
    {
        foreach (HtmlSlotPolicy::cases() as $policy) {
            $out = $this->sanitizer->sanitize($garbage, $policy);
            $label = $policy->value;

            // No executable <script> element survives in any policy.
            self::assertStringNotContainsString('<script', $out, $label);
            // Illegal C0 control chars are always stripped.
            self::assertDoesNotMatchRegularExpression(
                '/[\x00-\x08\x0B\x0C\x0E-\x1F]/',
                $out,
                $label,
            );

            if (HtmlSlotPolicy::Escape !== $policy) {
                // Parsed policies additionally strip event handlers and js: URIs.
                self::assertStringNotContainsString('javascript:', $out, $label);
                self::assertStringNotContainsString('onerror', $out, $label);
            } else {
                // Escape leaves zero live markup — every '<' is entity-encoded.
                self::assertStringNotContainsString('<', $out, $label);
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function garbageProvider(): iterable
    {
        yield 'script' => ['<script>alert(1)</script>'];
        yield 'onerror img' => ['<img src=x onerror="alert(1)">'];
        yield 'javascript href' => ['<a href="javascript:alert(1)">x</a>'];
        yield 'control chars' => ["A\x00B\x0BC\x1FD"];
        yield 'unclosed tag' => ['<div><span>oops'];
        yield 'emoji + entity' => ['Cena 10€ 🚀 <b>&amp;</b>'];
    }
}
