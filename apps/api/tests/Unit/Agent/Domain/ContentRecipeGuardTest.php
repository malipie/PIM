<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Domain;

use App\Agent\Domain\Entity\ContentRecipe;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AICG-P1-01 (#2327) — shape guards of the ContentRecipe aggregate:
 * `constraints.format` is pinned to plain|html and `source_attributes`
 * to non-empty string codes; everything else in `constraints` stays
 * free-form for the SEO validator (decision C, ADR-0030).
 */
final class ContentRecipeGuardTest extends TestCase
{
    #[Test]
    public function acceptsPlainAndHtmlFormatsWithSeoSlots(): void
    {
        $recipe = new ContentRecipe(
            code: 'product_description',
            name: 'Opis produktu',
            targetAttribute: 'description',
            sourceAttributes: ['material', 'color', 'brand'],
            constraints: ['format' => ContentRecipe::FORMAT_HTML, 'max_len' => 1200, 'seo' => ['keyword' => 'HDMI', 'title_len' => 60, 'meta_len' => 155]],
        );

        self::assertSame(['material', 'color', 'brand'], $recipe->getSourceAttributes());
        self::assertSame('html', $recipe->getConstraints()['format']);
        self::assertFalse($recipe->isBuiltIn());
    }

    #[Test]
    public function rejectsUnknownFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('constraints.format');

        new ContentRecipe('r', 'R', 'description', [], ['format' => 'markdown']);
    }

    #[Test]
    public function rejectsUnknownFormatOnUpdate(): void
    {
        $recipe = new ContentRecipe('r', 'R', 'description');

        $this->expectException(InvalidArgumentException::class);
        $recipe->updateConstraints(['format' => 'pdf']);
    }

    #[Test]
    public function rejectsNonPositiveMaxLen(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('max_len');

        new ContentRecipe('r', 'R', 'description', [], ['format' => 'plain', 'max_len' => 0]);
    }

    #[Test]
    public function rejectsNonObjectSeoSlot(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('constraints.seo');

        new ContentRecipe('r', 'R', 'description', [], ['seo' => 'keyword=HDMI']);
    }

    #[Test]
    public function rejectsEmptyOrNonStringSourceAttributeCodes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('source_attributes');

        new ContentRecipe('r', 'R', 'description', ['material', '']);
    }

    #[Test]
    public function reindexesSourceAttributesToAList(): void
    {
        $recipe = new ContentRecipe('r', 'R', 'description');
        $recipe->updateSourceAttributes([2 => 'material', 5 => 'color']);

        self::assertSame(['material', 'color'], $recipe->getSourceAttributes());
    }

    #[Test]
    public function builtInFlagIsOneWay(): void
    {
        $recipe = new ContentRecipe('r', 'R', 'description');
        $recipe->markBuiltIn();

        self::assertTrue($recipe->isBuiltIn());
    }
}
