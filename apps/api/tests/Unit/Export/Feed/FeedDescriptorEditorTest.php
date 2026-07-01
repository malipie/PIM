<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Feed;

use App\Export\Feed\Application\Descriptor\FeedDescriptorEditor;
use App\Export\Feed\Domain\Descriptor\FeedDescriptor;
use App\Export\Feed\Domain\Descriptor\InvalidDescriptorException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * XMLF-P2-07 — custom descriptor structure editing (add/rename/remove slots,
 * set root/namespaces), always leaving a valid descriptor.
 */
final class FeedDescriptorEditorTest extends TestCase
{
    /** @return array<string, mixed> */
    private function blank(): array
    {
        return [
            'root' => ['element' => 'products'],
            'item' => [
                'element' => 'product',
                'slots' => [['slot' => 'sku', 'node' => 'element', 'fmt' => 'text']],
            ],
        ];
    }

    #[Test]
    public function addsRenamesAndRemovesSlots(): void
    {
        $editor = new FeedDescriptorEditor();

        $d = $editor->addSlot($this->blank(), ['slot' => 'name', 'node' => 'element', 'fmt' => 'text']);
        self::assertNotNull(FeedDescriptor::fromArray($d)->findSlot('name'));

        $d = $editor->renameSlot($d, 'name', 'title');
        $parsed = FeedDescriptor::fromArray($d);
        self::assertNull($parsed->findSlot('name'));
        self::assertNotNull($parsed->findSlot('title'));

        $d = $editor->removeSlot($d, 'title');
        self::assertNull(FeedDescriptor::fromArray($d)->findSlot('title'));
        self::assertNotNull(FeedDescriptor::fromArray($d)->findSlot('sku'));
    }

    #[Test]
    public function setRootUpdatesElementAndNamespaces(): void
    {
        $d = new FeedDescriptorEditor()->setRoot($this->blank(), 'rss', ['version' => '2.0'], ['g' => 'http://x']);

        $parsed = FeedDescriptor::fromArray($d);
        self::assertSame('rss', $parsed->rootElement);
        self::assertSame('2.0', $parsed->rootAttributes['version']);
        self::assertSame('http://x', $parsed->namespaces['g']);
    }

    #[Test]
    public function rejectsAddingSlotWithIllegalName(): void
    {
        $this->expectException(InvalidDescriptorException::class);
        new FeedDescriptorEditor()->addSlot($this->blank(), ['slot' => 'bad.name', 'node' => 'element', 'fmt' => 'text']);
    }

    #[Test]
    public function rejectsRemovingUnknownSlot(): void
    {
        $this->expectException(InvalidDescriptorException::class);
        new FeedDescriptorEditor()->removeSlot($this->blank(), 'nope');
    }
}
