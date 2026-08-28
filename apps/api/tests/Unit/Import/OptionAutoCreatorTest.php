<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeOption;
use App\Catalog\Domain\Repository\AttributeOptionRepositoryInterface;
use App\Import\Application\Service\OptionAutoCreator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class OptionAutoCreatorTest extends TestCase
{
    #[Test]
    public function mintsMissingOptionWithSluggedPolishCode(): void
    {
        $repo = new InMemoryAttributeOptionRepository();
        $creator = new OptionAutoCreator($repo);
        $color = new Attribute('color', ['en' => 'Color'], AttributeType::Select);

        $code = $creator->resolve($color, 'Beżowy', create: true);

        self::assertSame('bezowy', $code);
        self::assertCount(1, $repo->saved);
        self::assertSame('bezowy', $repo->saved[0]->getCode());
        self::assertSame(['pl' => 'Beżowy'], $repo->saved[0]->getLabel());
        self::assertSame(0, $repo->saved[0]->getPosition());
    }

    #[Test]
    public function reusesExistingOptionMatchedByLabelCaseInsensitive(): void
    {
        $color = new Attribute('color', ['en' => 'Color'], AttributeType::Select);
        $repo = new InMemoryAttributeOptionRepository();
        $repo->seed($color, new AttributeOption($color, 'bezowy', ['pl' => 'Beżowy'], 0));
        $creator = new OptionAutoCreator($repo);

        $code = $creator->resolve($color, 'BEŻOWY', create: true);

        self::assertSame('bezowy', $code);
        self::assertCount(0, $repo->saved, 'must not mint a duplicate when a label already matches');
    }

    #[Test]
    public function reusesExistingOptionMatchedByCode(): void
    {
        $color = new Attribute('color', ['en' => 'Color'], AttributeType::Select);
        $repo = new InMemoryAttributeOptionRepository();
        $repo->seed($color, new AttributeOption($color, 'red', ['pl' => 'Czerwony'], 0));
        $creator = new OptionAutoCreator($repo);

        self::assertSame('red', $creator->resolve($color, 'red', create: true));
        self::assertCount(0, $repo->saved);
    }

    #[Test]
    public function suffixesCodeOnSlugCollision(): void
    {
        $attr = new Attribute('material', ['en' => 'Material'], AttributeType::Select);
        $repo = new InMemoryAttributeOptionRepository();
        // Existing option already owns the slug "skora" but under a different
        // (non-matching) label, and the incoming accented value neither equals
        // the code case-insensitively nor matches the label — so it mints, and
        // its slug collides with the taken "skora".
        $repo->seed($attr, new AttributeOption($attr, 'skora', ['pl' => 'Materiał skórzany'], 0));
        $creator = new OptionAutoCreator($repo);

        $code = $creator->resolve($attr, 'Skóra', create: true);

        self::assertSame('skora_2', $code);
        self::assertSame(1, $repo->saved[0]->getPosition(), 'new option appends after the existing max position');
    }

    #[Test]
    public function caseInsensitiveCodeMatchTakesPriorityOverCollidingLabel(): void
    {
        // Regression for the review hijack finding: option A has code 'l', option
        // B carries the LABEL 'L'. Importing 'L' must resolve to code 'l' (the
        // case-insensitive code match), never option B's code via the label.
        $size = new Attribute('size', ['en' => 'Size'], AttributeType::Multiselect);
        $repo = new InMemoryAttributeOptionRepository();
        $repo->seed($size, new AttributeOption($size, 'l', ['pl' => 'Large'], 0));
        $repo->seed($size, new AttributeOption($size, 'xl', ['pl' => 'L'], 1));
        $creator = new OptionAutoCreator($repo);

        self::assertSame('l', $creator->resolve($size, 'L', create: true));
        self::assertCount(0, $repo->saved, 'an upper-cased existing code is matched, not minted');
    }

    #[Test]
    public function clampsMintedCodeToColumnLength(): void
    {
        $attr = new Attribute('note', ['en' => 'Note'], AttributeType::Select);
        $repo = new InMemoryAttributeOptionRepository();
        $creator = new OptionAutoCreator($repo);

        $code = $creator->resolve($attr, str_repeat('Bardzo długa wartość ', 10), create: true);

        self::assertLessThanOrEqual(64, mb_strlen($code), 'minted code never exceeds the 64-char column');
    }

    #[Test]
    public function stopsMintingPastThePerRunCeiling(): void
    {
        $attr = new Attribute('freeform', ['en' => 'Freeform'], AttributeType::Select);
        $repo = new InMemoryAttributeOptionRepository();
        $creator = new OptionAutoCreator($repo);

        for ($i = 0; $i < 10_000; ++$i) {
            $creator->resolve($attr, "value-{$i}", create: true);
        }
        // The 10001st distinct value is past the ceiling → returned raw, not minted.
        $overflow = $creator->resolve($attr, 'one-too-many', create: true);

        self::assertSame('one-too-many', $overflow);
        self::assertCount(10_000, $repo->saved, 'minting is capped per run');
    }

    #[Test]
    public function appendsSecondMintAfterFirstWithinSameRun(): void
    {
        $size = new Attribute('size', ['en' => 'Size'], AttributeType::Multiselect);
        $repo = new InMemoryAttributeOptionRepository();
        $creator = new OptionAutoCreator($repo);

        self::assertSame('36', $creator->resolve($size, '36', create: true));
        self::assertSame('37', $creator->resolve($size, '37', create: true));
        self::assertSame([0, 1], array_map(static fn (AttributeOption $o): int => $o->getPosition(), $repo->saved));
    }

    #[Test]
    public function returnsRawUnchangedWhenCreateDisabled(): void
    {
        $repo = new InMemoryAttributeOptionRepository();
        $creator = new OptionAutoCreator($repo);
        $color = new Attribute('color', ['en' => 'Color'], AttributeType::Select);

        self::assertSame('Beżowy', $creator->resolve($color, 'Beżowy', create: false));
        self::assertCount(0, $repo->saved, 'create=false never mints');
    }

    #[Test]
    public function resetDropsCacheSoFreshLookupRuns(): void
    {
        $color = new Attribute('color', ['en' => 'Color'], AttributeType::Select);
        $repo = new InMemoryAttributeOptionRepository();
        $creator = new OptionAutoCreator($repo);

        $creator->resolve($color, 'Czerwony', create: true); // caches + mints "czerwony"
        $repo->queryCount = 0;
        $creator->reset();
        $creator->resolve($color, 'Zielony', create: true);

        self::assertSame(1, $repo->queryCount, 'reset() forces a fresh findByAttribute on next resolve');
    }
}

/**
 * Minimal in-memory double — records saves and serves seeded options.
 */
final class InMemoryAttributeOptionRepository implements AttributeOptionRepositoryInterface
{
    /** @var list<AttributeOption> */
    public array $saved = [];

    public int $queryCount = 0;

    /** @var array<string, list<AttributeOption>> keyed by attribute id */
    private array $byAttribute = [];

    public function seed(Attribute $attribute, AttributeOption $option): void
    {
        $this->byAttribute[$attribute->getId()->toRfc4122()][] = $option;
    }

    public function findById(Uuid $id): ?AttributeOption
    {
        return null;
    }

    public function findByAttribute(Attribute $attribute): array
    {
        ++$this->queryCount;

        return $this->byAttribute[$attribute->getId()->toRfc4122()] ?? [];
    }

    public function findCodesByAttribute(Attribute $attribute): array
    {
        return array_map(static fn (AttributeOption $o): string => $o->getCode(), $this->findByAttribute($attribute));
    }

    public function findByAttributes(array $attributes): array
    {
        return [];
    }

    public function save(AttributeOption $attributeOption): void
    {
        $this->saved[] = $attributeOption;
        $this->byAttribute[$attributeOption->getAttribute()->getId()->toRfc4122()][] = $attributeOption;
    }

    public function remove(AttributeOption $attributeOption): void
    {
    }
}
