<?php

declare(strict_types=1);

namespace App\Tests\Unit\Asset;

use App\Asset\Domain\Entity\Asset;
use App\Asset\Domain\Entity\AssetVariant;
use App\Asset\Domain\Repository\AssetRepositoryInterface;
use App\Asset\Infrastructure\Service\StorageAssetInliner;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToReadFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class StorageAssetInlinerTest extends TestCase
{
    #[Test]
    public function inlinesBareUuidAsDataUriPreferringMediumVariant(): void
    {
        $id = Uuid::v7();
        $repo = $this->createStub(AssetRepositoryInterface::class);
        $repo->method('findById')->willReturn($this->readyAssetWithVariants($id));

        $storage = $this->createMock(FilesystemOperator::class);
        // Medium (800) is preferred over thumb/original for print.
        $storage->expects(self::once())->method('read')->with('demo/medium.jpg')->willReturn('MEDIUM-BYTES');

        $inliner = new StorageAssetInliner($repo, $storage);

        self::assertSame(
            'data:image/jpeg;base64,'.base64_encode('MEDIUM-BYTES'),
            $inliner->toDataUri($id->toRfc4122()),
        );
    }

    #[Test]
    public function extractsIdFromPreviewUrl(): void
    {
        $id = Uuid::v7();
        $repo = $this->createStub(AssetRepositoryInterface::class);
        $repo->method('findById')->willReturn($this->readyAssetWithVariants($id));

        $storage = $this->createStub(FilesystemOperator::class);
        $storage->method('read')->willReturn('BYTES');

        $inliner = new StorageAssetInliner($repo, $storage);

        $result = $inliner->toDataUri(\sprintf('/api/assets/%s/preview', $id->toRfc4122()));
        self::assertNotNull($result);
        self::assertStringStartsWith('data:image/jpeg;base64,', $result);
    }

    #[Test]
    public function fallsBackToOriginalWhenThumbnailsPending(): void
    {
        $id = Uuid::v7();
        // Pending thumbnails → the variant loop is skipped; only the original
        // blob is used.
        $asset = new Asset('code', 'f.jpg', 'image/jpeg', 10, 'demo/original.jpg', $id);
        $asset->addVariant(new AssetVariant($asset, AssetVariant::CODE_ORIGINAL, 'demo/original.jpg', 'image/jpeg', 10));

        $repo = $this->createStub(AssetRepositoryInterface::class);
        $repo->method('findById')->willReturn($asset);

        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects(self::once())->method('read')->with('demo/original.jpg')->willReturn('ORIG');

        $inliner = new StorageAssetInliner($repo, $storage);

        self::assertSame('data:image/jpeg;base64,'.base64_encode('ORIG'), $inliner->toDataUri($id->toRfc4122()));
    }

    #[Test]
    public function returnsNullForNonAssetReference(): void
    {
        $repo = $this->createMock(AssetRepositoryInterface::class);
        $repo->expects(self::never())->method('findById');
        $storage = $this->createStub(FilesystemOperator::class);

        $inliner = new StorageAssetInliner($repo, $storage);

        self::assertNull($inliner->toDataUri('https://example.com/logo.png'));
        self::assertNull($inliner->toDataUri(''));
        self::assertNull($inliner->toDataUri('not-a-uuid'));
    }

    #[Test]
    public function returnsNullWhenAssetMissing(): void
    {
        $repo = $this->createStub(AssetRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);
        $repo->method('findByObjectId')->willReturn(null);
        $storage = $this->createStub(FilesystemOperator::class);

        $inliner = new StorageAssetInliner($repo, $storage);

        self::assertNull($inliner->toDataUri(Uuid::v7()->toRfc4122()));
    }

    #[Test]
    public function returnsNullWhenBlobMissing(): void
    {
        $id = Uuid::v7();
        $repo = $this->createStub(AssetRepositoryInterface::class);
        $repo->method('findById')->willReturn($this->readyAssetWithVariants($id));

        $storage = $this->createStub(FilesystemOperator::class);
        $storage->method('read')->willThrowException(UnableToReadFile::fromLocation('demo/medium.jpg'));

        $inliner = new StorageAssetInliner($repo, $storage);

        self::assertNull($inliner->toDataUri($id->toRfc4122()));
    }

    #[Test]
    public function memoisesResolutionPerReference(): void
    {
        $id = Uuid::v7();
        $repo = $this->createMock(AssetRepositoryInterface::class);
        // The same logo repeats across every product row — resolve it once.
        $repo->expects(self::once())->method('findById')->willReturn($this->readyAssetWithVariants($id));

        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects(self::once())->method('read')->willReturn('BYTES');

        $inliner = new StorageAssetInliner($repo, $storage);

        $ref = $id->toRfc4122();
        $first = $inliner->toDataUri($ref);
        $second = $inliner->toDataUri($ref);
        self::assertSame($first, $second);
    }

    private function readyAssetWithVariants(Uuid $id): Asset
    {
        $asset = new Asset('code', 'f.jpg', 'image/jpeg', 100, 'demo/original.jpg', $id);
        $asset->addVariant(new AssetVariant($asset, AssetVariant::CODE_ORIGINAL, 'demo/original.jpg', 'image/jpeg', 100));
        $asset->addVariant(new AssetVariant($asset, AssetVariant::CODE_THUMB, 'demo/thumb.jpg', 'image/jpeg', 20));
        $asset->addVariant(new AssetVariant($asset, AssetVariant::CODE_MEDIUM, 'demo/medium.jpg', 'image/jpeg', 50));
        $asset->markThumbnailsReady(800, 600, null);

        return $asset;
    }
}
