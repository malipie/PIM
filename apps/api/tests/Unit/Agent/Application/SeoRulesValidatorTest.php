<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Application;

use App\Agent\Application\Content\SeoRulesValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AICG-P4-01 (#2337, decision C) — SEO rules parametrised by the
 * recipe: length budget, keyword presence, stuffing ceiling. Structured
 * violations, never exceptions.
 */
final class SeoRulesValidatorTest extends TestCase
{
    #[Test]
    public function validCopyPassesEveryRule(): void
    {
        $violations = new SeoRulesValidator()->validate(
            'Kabel HDMI 2.1 o długości 2 m do konsol i telewizorów 4K.',
            ['keyword' => 'HDMI'],
            maxLen: 155,
        );

        self::assertSame([], $violations);
    }

    #[Test]
    public function overlongCopyViolatesTheBudget(): void
    {
        $violations = new SeoRulesValidator()->validate(str_repeat('a', 200), [], maxLen: 155);

        self::assertCount(1, $violations);
        self::assertStringContainsString('too_long: 200', $violations[0]);
    }

    #[Test]
    public function missingKeywordIsAViolation(): void
    {
        $violations = new SeoRulesValidator()->validate(
            'Przewód wideo wysokiej jakości.',
            ['keyword' => 'HDMI'],
        );

        self::assertCount(1, $violations);
        self::assertStringContainsString('missing_keyword: "HDMI"', $violations[0]);
    }

    #[Test]
    public function keywordMatchIsCaseInsensitive(): void
    {
        $violations = new SeoRulesValidator()->validate('Kabel hdmi 2.1.', ['keyword' => 'HDMI']);

        self::assertSame([], $violations);
    }

    #[Test]
    public function stuffingIsFlaggedAboveTheDensityCeiling(): void
    {
        $violations = new SeoRulesValidator()->validate(
            'HDMI HDMI HDMI kabel HDMI do HDMI.',
            ['keyword' => 'HDMI'],
        );

        self::assertCount(1, $violations);
        self::assertStringContainsString('keyword_stuffing', $violations[0]);
    }

    #[Test]
    public function repeatedKeywordInLongNaturalCopyIsNotStuffing(): void
    {
        $copy = 'Kabel HDMI 2.1 obsługuje rozdzielczość 4K przy 120 Hz. Złącze HDMI pasuje do konsol, '
            .'komputerów i telewizorów. Oplot nylonowy chroni przewód przed uszkodzeniami, a pozłacane '
            .'styki zapewniają stabilny sygnał przez lata codziennego użytkowania bez zakłóceń obrazu.';

        self::assertSame([], new SeoRulesValidator()->validate($copy, ['keyword' => 'HDMI'], maxLen: 300));
    }

    #[Test]
    public function multipleViolationsAreAllReported(): void
    {
        $violations = new SeoRulesValidator()->validate(
            str_repeat('opis produktu bez slowa kluczowego ', 6),
            ['keyword' => 'HDMI'],
            maxLen: 100,
        );

        self::assertCount(2, $violations);
    }

    #[Test]
    public function budgetPicksTitleLenForTitleTargetsAndMetaLenOtherwise(): void
    {
        $validator = new SeoRulesValidator();
        $rules = ['title_len' => 60, 'meta_len' => 155];

        self::assertSame(60, $validator->budgetFor('meta_title', $rules));
        self::assertSame(60, $validator->budgetFor('seo_title', $rules));
        self::assertSame(155, $validator->budgetFor('meta_description', $rules));
        self::assertSame(155, $validator->budgetFor('seo_text', $rules));
        self::assertNull($validator->budgetFor('meta_description', ['title_len' => 60]));
    }
}
