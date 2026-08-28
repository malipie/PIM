<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\HtmlSanitizer;
use App\Catalog\Application\ValueWriteCore;
use App\Catalog\Contracts\AttributeType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * AUD-033 / W2-2 (C-4) — the wysiwyg HTML must be sanitised on the WRITE
 * path, inside the single shared {@see ValueWriteCore::normalise()} both
 * the admin ({@see \App\Catalog\Application\ObjectAttributesUpserter}) and
 * the import ({@see \App\Catalog\Application\BatchValueWriter}) flow through.
 *
 * Before the fix a `<script>` / `onerror=` / `javascript:` href landed in
 * `object_values.value` verbatim (proven empirically in the audit's C-3/C-4);
 * after it the stored envelope carries the cleaned markup, independent of
 * whatever the frontend DOMPurify does at render time. The sanitizer is the
 * collaborator added reflectively (same pattern as the valueValidator in the
 * sibling ValueWriteCore tests), so the DI-heavy readonly constructor stays
 * out of the unit test.
 */
final class ValueWriteCoreWysiwygSanitizationTest extends TestCase
{
    private function core(): ValueWriteCore
    {
        $core = new ReflectionClass(ValueWriteCore::class)->newInstanceWithoutConstructor();
        new ReflectionClass(ValueWriteCore::class)
            ->getProperty('htmlSanitizer')
            ->setValue($core, new HtmlSanitizer());

        return $core;
    }

    #[Test]
    public function normaliseStripsScriptFromWysiwyg(): void
    {
        $envelope = $this->core()->normalise(AttributeType::Wysiwyg, [
            'value' => '<p>ok</p><script>alert(1)</script>',
        ]);

        self::assertIsString($envelope['value']);
        self::assertStringNotContainsStringIgnoringCase('<script', $envelope['value']);
        self::assertStringNotContainsString('alert(1)', $envelope['value']);
        self::assertStringContainsString('<p>ok</p>', $envelope['value']);
    }

    #[Test]
    public function normaliseStripsEventHandlerFromWysiwyg(): void
    {
        $envelope = $this->core()->normalise(AttributeType::Wysiwyg, [
            'value' => '<img src=x onerror=alert(1)>',
        ]);

        self::assertIsString($envelope['value']);
        self::assertStringNotContainsStringIgnoringCase('onerror', $envelope['value']);
        self::assertStringNotContainsString('alert(1)', $envelope['value']);
    }

    #[Test]
    public function normaliseRemovesJavascriptHrefFromWysiwyg(): void
    {
        $envelope = $this->core()->normalise(AttributeType::Wysiwyg, [
            'value' => '<a href="javascript:alert(1)">click</a>',
        ]);

        self::assertIsString($envelope['value']);
        self::assertStringNotContainsStringIgnoringCase('javascript:', $envelope['value']);
    }

    #[Test]
    public function normaliseRemovesDataHrefFromWysiwyg(): void
    {
        $envelope = $this->core()->normalise(AttributeType::Wysiwyg, [
            'value' => '<a href="data:text/html,<script>alert(1)</script>">x</a>',
        ]);

        self::assertIsString($envelope['value']);
        self::assertStringNotContainsStringIgnoringCase('data:text/html', $envelope['value']);
        self::assertStringNotContainsString('alert(1)', $envelope['value']);
    }

    #[Test]
    public function normalisePreservesLegitimateRichText(): void
    {
        $envelope = $this->core()->normalise(AttributeType::Wysiwyg, [
            'value' => '<p><strong>ok</strong></p>',
        ]);

        self::assertSame(['value' => '<p><strong>ok</strong></p>'], $envelope);
    }

    #[Test]
    public function normalisePreservesHttpsLinkInWysiwyg(): void
    {
        $envelope = $this->core()->normalise(AttributeType::Wysiwyg, [
            'value' => '<a href="https://example.com">link</a>',
        ]);

        self::assertIsString($envelope['value']);
        self::assertStringContainsString('href="https://example.com"', $envelope['value']);
    }

    #[Test]
    public function normaliseLeavesNonWysiwygUntouched(): void
    {
        // A text attribute must NOT be HTML-sanitised — its `<` is data, and the
        // additionalProperties/format check (AUD-032) is what guards it.
        $envelope = $this->core()->normalise(AttributeType::Text, [
            'value' => 'a < b && c > d',
        ]);

        self::assertSame(['value' => 'a < b && c > d'], $envelope);
    }
}
