<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Feed;

use App\Export\Contracts\FeedProductScope;
use App\Export\Contracts\FeedProductValues;
use App\Export\Feed\Application\Preview\FeedPreviewService;
use App\Export\Feed\Domain\Descriptor\FeedDescriptor;
use App\Export\Feed\Domain\Generator\FeedRequiredValidator;
use App\Export\Feed\Domain\Mapping\FeedFieldMapping;
use App\Export\Feed\Domain\Mapping\FeedItemMapper;
use App\Export\Feed\Domain\Mapping\FeedTransformApplier;
use DOMDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * XMLF-P4-04 — preview: first N samples rendered in-memory with a health report.
 */
final class FeedPreviewServiceTest extends TestCase
{
    private function descriptor(): FeedDescriptor
    {
        return FeedDescriptor::fromArray([
            'root' => ['element' => 'products'],
            'item' => [
                'element' => 'product',
                'slots' => [
                    ['slot' => 'sku', 'node' => 'element', 'required' => true, 'fmt' => 'text'],
                    ['slot' => 'name', 'node' => 'element', 'fmt' => 'text'],
                ],
            ],
        ]);
    }

    /**
     * @return list<FeedFieldMapping>
     */
    private function mappings(): array
    {
        return FeedFieldMapping::listFromArray([
            ['slot' => 'sku', 'source' => ['kind' => 'attribute', 'ref' => 'sku']],
            ['slot' => 'name', 'source' => ['kind' => 'attribute', 'ref' => 'name']],
        ]);
    }

    private function service(FeedProductValues $source): FeedPreviewService
    {
        return new FeedPreviewService($source, new FeedItemMapper(new FeedTransformApplier()), new FeedRequiredValidator());
    }

    private function scope(): FeedProductScope
    {
        return new FeedProductScope(Uuid::v7(), ['sku', 'name'], null, null, null);
    }

    #[Test]
    public function previewCapsSamplesAndReportsHealth(): void
    {
        $source = new class implements FeedProductValues {
            public function forScope(FeedProductScope $scope): iterable
            {
                yield ['sku' => 'A-1', 'name' => 'Alpha'];
                yield ['sku' => 'A-2', 'name' => 'Beta'];
                yield ['name' => 'No SKU']; // missing required sku → health warning
                yield ['sku' => 'A-4', 'name' => 'Delta'];
                yield ['sku' => 'A-5', 'name' => 'Echo'];
                yield ['sku' => 'A-6', 'name' => 'Should not appear']; // beyond limit 5
            }
        };

        $result = $this->service($source)->preview($this->descriptor(), $this->mappings(), $this->scope(), [], 5);

        self::assertSame(5, $result['sample_count']);

        $dom = new DOMDocument();
        self::assertTrue($dom->loadXML($result['xml']));
        self::assertSame(5, $dom->getElementsByTagName('product')->length);
        self::assertStringNotContainsString('Should not appear', $result['xml']);

        // The SKU-less sample raises exactly one health warning for the sku slot.
        $skuWarnings = array_filter($result['health'], static fn (array $h): bool => 'sku' === $h['slot']);
        self::assertCount(1, $skuWarnings);
        self::assertSame('warning', array_values($skuWarnings)[0]['level']);
    }

    #[Test]
    public function previewClampsLimitToAtLeastOne(): void
    {
        $source = new class implements FeedProductValues {
            public function forScope(FeedProductScope $scope): iterable
            {
                yield ['sku' => 'A-1', 'name' => 'Alpha'];
                yield ['sku' => 'A-2', 'name' => 'Beta'];
            }
        };

        $result = $this->service($source)->preview($this->descriptor(), $this->mappings(), $this->scope(), [], 0);

        self::assertSame(1, $result['sample_count']);
    }
}
