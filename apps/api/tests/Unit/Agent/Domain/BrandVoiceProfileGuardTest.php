<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Domain;

use App\Agent\Domain\Entity\BrandVoiceProfile;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AICG-P1-02 (#2328) — shape guards of the BrandVoiceProfile aggregate:
 * glossary = {term, use} pairs, examples = {good, bad} pairs,
 * banned_words = non-empty strings (plan §6.4).
 */
final class BrandVoiceProfileGuardTest extends TestCase
{
    #[Test]
    public function acceptsWellFormedVoiceProfile(): void
    {
        $voice = new BrandVoiceProfile(
            name: 'Ekspercki',
            tone: 'ekspercki, zwięzły',
            glossary: [['term' => 'smart TV', 'use' => 'telewizor smart']],
            bannedWords: ['tani'],
            examples: [['good' => 'Precyzyjny opis parametrów.', 'bad' => 'Super mega okazja!!!']],
        );

        self::assertSame('ekspercki, zwięzły', $voice->getTone());
        self::assertSame(['tani'], $voice->getBannedWords());
        self::assertFalse($voice->isDefault());
    }

    #[Test]
    public function rejectsGlossaryWithoutTermUsePair(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('glossary');

        new BrandVoiceProfile('V', 'tone', glossary: [['term' => 'x']]);
    }

    #[Test]
    public function rejectsEmptyGlossaryTerm(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BrandVoiceProfile('V', 'tone', glossary: [['term' => '', 'use' => 'y']]);
    }

    #[Test]
    public function rejectsNonStringBannedWord(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('banned_words');

        new BrandVoiceProfile('V', 'tone', bannedWords: ['tani', 42]);
    }

    #[Test]
    public function rejectsExampleWithoutGoodBadPair(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('examples');

        new BrandVoiceProfile('V', 'tone', examples: [['good' => 'ok']]);
    }

    #[Test]
    public function rejectsMalformedShapesOnUpdate(): void
    {
        $voice = new BrandVoiceProfile('V', 'tone');

        $this->expectException(InvalidArgumentException::class);
        $voice->updateExamples([['good' => 'ok', 'bad' => 7]]);
    }

    #[Test]
    public function reindexesListsOnUpdate(): void
    {
        $voice = new BrandVoiceProfile('V', 'tone');
        $voice->updateBannedWords([3 => 'tani', 9 => 'promocja']);

        self::assertSame(['tani', 'promocja'], $voice->getBannedWords());
    }
}
