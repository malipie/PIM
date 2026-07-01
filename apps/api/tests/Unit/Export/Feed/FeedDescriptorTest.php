<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Feed;

use App\Export\Feed\Domain\Descriptor\FeedDescriptor;
use App\Export\Feed\Domain\Descriptor\InvalidDescriptorException;
use App\Export\Feed\Domain\Descriptor\SlotNodeKind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * XMLF-P2-01 — FeedDescriptor is the canonical, validated descriptor shape:
 * round-trips the RSS-like (Google) and flat (Ceneo) forms and rejects
 * structurally invalid payloads.
 */
final class FeedDescriptorTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function googleSlots(): array
    {
        return [
            ['slot' => 'g:id', 'node' => 'element', 'required' => true, 'maxLength' => 50, 'fmt' => 'text'],
            ['slot' => 'g:gtin', 'node' => 'element', 'requiredOneOf' => ['g:gtin', 'g:mpn'], 'fmt' => 'text'],
            ['slot' => 'g:mpn', 'node' => 'element', 'requiredOneOf' => ['g:gtin', 'g:mpn'], 'fmt' => 'text'],
            ['slot' => 'g:availability', 'node' => 'element', 'fmt' => 'enum', 'enums' => ['in_stock', 'out_of_stock']],
        ];
    }

    /**
     * @param list<array<string, mixed>> $slots
     *
     * @return array<string, mixed>
     */
    private function google(array $slots): array
    {
        return [
            'root' => [
                'element' => 'rss',
                'attributes' => ['version' => '2.0'],
                'namespaces' => ['g' => 'http://base.google.com/ns/1.0'],
            ],
            'channel' => [
                'element' => 'channel',
                'header' => [['element' => 'title', 'source' => ['kind' => 'static', 'value' => '{feed_name}']]],
                'item' => ['element' => 'item', 'slots' => $slots],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $slots
     *
     * @return array<string, mixed>
     */
    private function ceneo(array $slots): array
    {
        return [
            'root' => ['element' => 'offers', 'attributes' => ['version' => '1']],
            'item' => ['element' => 'o', 'slots' => $slots],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function ceneoSlots(): array
    {
        return [
            ['target' => 'id', 'node' => 'attribute', 'parent' => 'o', 'required' => true, 'fmt' => 'text'],
            ['target' => 'name', 'node' => 'element', 'element' => 'name', 'required' => true, 'fmt' => 'text'],
            ['target' => 'ceneo_image', 'node' => 'repeatable', 'element' => 'i', 'wrapIn' => 'imgs', 'fmt' => 'url'],
            ['target' => 'attr', 'node' => 'keyvalue', 'element' => 'a', 'wrapIn' => 'attrs', 'fmt' => 'text'],
        ];
    }

    #[Test]
    public function parsesAndRoundTripsGoogleShape(): void
    {
        $descriptor = FeedDescriptor::fromArray($this->google($this->googleSlots()));

        self::assertSame('rss', $descriptor->rootElement);
        self::assertSame('channel', $descriptor->channelElement);
        self::assertSame('item', $descriptor->itemElement);
        self::assertSame('http://base.google.com/ns/1.0', $descriptor->namespaces['g']);
        self::assertCount(4, $descriptor->slots);

        $reparsed = FeedDescriptor::fromArray($descriptor->toArray());
        self::assertSame($descriptor->toArray(), $reparsed->toArray());
    }

    #[Test]
    public function parsesFlatCeneoShapeWithAttributeAndWrapNodes(): void
    {
        $descriptor = FeedDescriptor::fromArray($this->ceneo($this->ceneoSlots()));

        self::assertSame('offers', $descriptor->rootElement);
        self::assertNull($descriptor->channelElement);
        self::assertSame('o', $descriptor->itemElement);

        $id = $descriptor->findSlot('id');
        self::assertNotNull($id);
        self::assertSame(SlotNodeKind::Attribute, $id->node);
        self::assertSame('o', $id->parent);

        $image = $descriptor->findSlot('ceneo_image');
        self::assertNotNull($image);
        self::assertSame('imgs', $image->wrapIn);
        self::assertSame('i', $image->element);
    }

    #[Test]
    public function rejectsIllegalElementName(): void
    {
        $slots = [...$this->googleSlots(), ['slot' => 'description.pl', 'node' => 'element', 'fmt' => 'text']];

        $this->expectException(InvalidDescriptorException::class);
        FeedDescriptor::fromArray($this->google($slots));
    }

    #[Test]
    public function rejectsAttributeWithoutParent(): void
    {
        $slots = [['target' => 'id', 'node' => 'attribute', 'fmt' => 'text']]; // no parent

        $this->expectException(InvalidDescriptorException::class);
        FeedDescriptor::fromArray($this->ceneo($slots));
    }

    #[Test]
    public function rejectsEnumFormatWithoutValues(): void
    {
        $slots = [...$this->googleSlots(), ['slot' => 'g:condition', 'node' => 'element', 'fmt' => 'enum']];

        $this->expectException(InvalidDescriptorException::class);
        FeedDescriptor::fromArray($this->google($slots));
    }

    #[Test]
    public function rejectsRequiredOneOfReferencingUnknownSlot(): void
    {
        $slots = [...$this->googleSlots(), ['slot' => 'g:brand', 'node' => 'element', 'requiredOneOf' => ['g:nope'], 'fmt' => 'text']];

        $this->expectException(InvalidDescriptorException::class);
        FeedDescriptor::fromArray($this->google($slots));
    }

    #[Test]
    public function rejectsDuplicateSlotTarget(): void
    {
        $slots = [...$this->googleSlots(), ['slot' => 'g:id', 'node' => 'element', 'fmt' => 'text']];

        $this->expectException(InvalidDescriptorException::class);
        FeedDescriptor::fromArray($this->google($slots));
    }

    #[Test]
    public function rejectsMissingItem(): void
    {
        $this->expectException(InvalidDescriptorException::class);
        FeedDescriptor::fromArray(['root' => ['element' => 'rss']]);
    }
}
