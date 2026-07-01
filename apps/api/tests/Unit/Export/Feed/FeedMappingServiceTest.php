<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Feed;

use App\Catalog\Contracts\Query\AttributeSummary;
use App\Export\Feed\Application\Mapping\FeedMappingService;
use App\Export\Feed\Application\Mapping\InvalidMappingException;
use App\Export\Feed\Application\Mapping\SlotFormatCompatibility;
use App\Export\Feed\Domain\Descriptor\SlotFormat;
use App\Export\Feed\Domain\Entity\FeedProfile;
use App\Export\Feed\Domain\Enum\FeedTemplateKind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * XMLF-P3-01 — mapper read model + write validator.
 */
final class FeedMappingServiceTest extends TestCase
{
    private function profile(): FeedProfile
    {
        return new FeedProfile(
            code: 'google_pl',
            name: 'Google PL',
            templateKind: FeedTemplateKind::GoogleShopping,
            objectTypeId: Uuid::v7(),
            descriptor: [
                'root' => ['element' => 'products'],
                'item' => [
                    'element' => 'product',
                    'slots' => [
                        ['slot' => 'id', 'node' => 'element', 'required' => true, 'fmt' => 'text'],
                        ['slot' => 'price', 'node' => 'element', 'required' => true, 'fmt' => 'price'],
                        ['slot' => 'gtin', 'node' => 'element', 'fmt' => 'text', 'requiredOneOf' => ['gtin', 'mpn']],
                        ['slot' => 'mpn', 'node' => 'element', 'fmt' => 'text', 'requiredOneOf' => ['gtin', 'mpn']],
                    ],
                ],
            ],
            fieldMappings: [
                ['slot' => 'id', 'source' => ['kind' => 'attribute', 'ref' => 'sku']],
                ['slot' => 'price', 'source' => ['kind' => 'attribute', 'ref' => 'title']], // text→price = warning
            ],
        );
    }

    /**
     * @return list<AttributeSummary>
     */
    private function catalog(): array
    {
        $tenant = Uuid::v7();

        return [
            new AttributeSummary(Uuid::v7(), $tenant, 'sku', ['pl' => 'SKU'], 'identifier', false, true, true, null, 'general', ['pl' => 'Ogólne'], 0),
            new AttributeSummary(Uuid::v7(), $tenant, 'title', ['pl' => 'Nazwa'], 'text', true, true, false, null, 'general', ['pl' => 'Ogólne'], 1),
            new AttributeSummary(Uuid::v7(), $tenant, 'price', ['pl' => 'Cena'], 'price', false, false, false, null, 'general', ['pl' => 'Ogólne'], 2),
            new AttributeSummary(Uuid::v7(), $tenant, 'gtin', ['pl' => 'GTIN'], 'text', false, false, false, null, 'general', ['pl' => 'Ogólne'], 3),
        ];
    }

    #[Test]
    public function compatibilityWarnsOnMismatchButNotOnTextSlot(): void
    {
        $compat = new SlotFormatCompatibility();

        self::assertNotNull($compat->warn(SlotFormat::Price, 'text'));
        self::assertNull($compat->warn(SlotFormat::Price, 'number'));
        self::assertNull($compat->warn(SlotFormat::Text, 'boolean')); // text slot accepts anything
        self::assertNull($compat->warn(SlotFormat::Date, 'datetime'));
        self::assertNotNull($compat->warn(SlotFormat::Date, 'text'));
    }

    #[Test]
    public function viewProjectsSlotsCoverageAndTypeWarning(): void
    {
        $view = new FeedMappingService()->view($this->profile(), $this->catalog());

        $coverage = $view['coverage'];
        self::assertIsArray($coverage);
        self::assertSame(4, $coverage['slots_total']);
        self::assertSame(2, $coverage['slots_mapped']);
        self::assertSame(2, $coverage['required_total']);
        self::assertSame(2, $coverage['required_mapped']);
        self::assertSame([], $coverage['missing_required']);
        self::assertSame([['targets' => ['gtin', 'mpn'], 'satisfied' => false]], $coverage['one_of_groups']);

        $transforms = $view['transforms'];
        self::assertIsArray($transforms);
        self::assertContains('truncate', $transforms);

        $attributes = $view['attributes'];
        self::assertIsArray($attributes);
        self::assertCount(4, $attributes);

        $byTarget = $this->slotsByTarget($view);
        self::assertNull($byTarget['id']['type_warning']);          // text slot, no warning
        self::assertNotNull($byTarget['price']['type_warning']);    // title(text) → price slot
        self::assertFalse($byTarget['gtin']['mapped']);
    }

    #[Test]
    public function applyUpdatePersistsNormalisedMappings(): void
    {
        $feed = $this->profile();
        $service = new FeedMappingService();

        $service->applyUpdate($feed, ['mappings' => [
            ['slot' => 'id', 'source' => ['kind' => 'attribute', 'ref' => 'sku']],
            ['slot' => 'price', 'source' => ['kind' => 'attribute', 'ref' => 'price'], 'transform' => ['kind' => 'price']],
            ['slot' => 'gtin', 'source' => ['kind' => 'attribute', 'ref' => 'gtin']],
        ]], $this->catalog());

        self::assertCount(3, $feed->getFieldMappings());

        $view = $service->view($feed, $this->catalog());
        $byTarget = $this->slotsByTarget($view);
        self::assertNull($byTarget['price']['type_warning']); // price(price attr) now clean

        $coverage = $view['coverage'];
        self::assertIsArray($coverage);
        self::assertSame([['targets' => ['gtin', 'mpn'], 'satisfied' => true]], $coverage['one_of_groups']);
    }

    /**
     * @param array<string, mixed> $view
     *
     * @return array<string, array<mixed, mixed>>
     */
    private function slotsByTarget(array $view): array
    {
        $slots = $view['slots'];
        self::assertIsArray($slots);

        $byTarget = [];
        foreach ($slots as $slot) {
            self::assertIsArray($slot);
            $target = $slot['target'];
            self::assertIsString($target);
            $byTarget[$target] = $slot;
        }

        return $byTarget;
    }

    #[Test]
    public function applyUpdateRejectsUnknownSlot(): void
    {
        $this->expectException(InvalidMappingException::class);
        new FeedMappingService()->applyUpdate($this->profile(), ['mappings' => [
            ['slot' => 'nope', 'source' => ['kind' => 'attribute', 'ref' => 'sku']],
        ]], $this->catalog());
    }

    #[Test]
    public function applyUpdateRejectsUnknownAttribute(): void
    {
        $this->expectException(InvalidMappingException::class);
        new FeedMappingService()->applyUpdate($this->profile(), ['mappings' => [
            ['slot' => 'id', 'source' => ['kind' => 'attribute', 'ref' => 'ghost']],
        ]], $this->catalog());
    }

    #[Test]
    public function applyUpdateRejectsUnknownTransform(): void
    {
        $this->expectException(InvalidMappingException::class);
        new FeedMappingService()->applyUpdate($this->profile(), ['mappings' => [
            ['slot' => 'id', 'source' => ['kind' => 'attribute', 'ref' => 'sku'], 'transform' => ['kind' => 'bogus']],
        ]], $this->catalog());
    }

    #[Test]
    public function applyUpdateRejectsStaticWithoutValue(): void
    {
        $this->expectException(InvalidMappingException::class);
        new FeedMappingService()->applyUpdate($this->profile(), ['mappings' => [
            ['slot' => 'id', 'source' => ['kind' => 'static']],
        ]], $this->catalog());
    }

    #[Test]
    public function applyUpdateRejectsDuplicateSlot(): void
    {
        $this->expectException(InvalidMappingException::class);
        new FeedMappingService()->applyUpdate($this->profile(), ['mappings' => [
            ['slot' => 'id', 'source' => ['kind' => 'attribute', 'ref' => 'sku']],
            ['slot' => 'id', 'source' => ['kind' => 'attribute', 'ref' => 'title']],
        ]], $this->catalog());
    }
}
