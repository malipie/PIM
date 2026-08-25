<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Import\Application\Service\StagedFileService;
use App\Import\Domain\Entity\StagedFile;
use App\Import\Domain\Repository\StagedFileRepositoryInterface;
use App\Shared\Application\TenantContext;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToCopyFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class StagedFileServiceTest extends TestCase
{
    #[Test]
    public function copyToKeyFallsBackToStreamingWhenNativeCopyIsUnavailable(): void
    {
        $sourceKey = 'tenant/staged/file/source.xlsx';
        $destinationKey = 'tenant/session/source.xlsx';
        $contents = 'xlsx bytes';
        $source = fopen('php://temp', 'w+');
        self::assertIsResource($source);
        fwrite($source, $contents);
        rewind($source);

        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects(self::once())
            ->method('copy')
            ->with($sourceKey, $destinationKey)
            ->willThrowException(UnableToCopyFile::fromLocationTo($sourceKey, $destinationKey));
        $storage->expects(self::once())
            ->method('readStream')
            ->with($sourceKey)
            ->willReturn($source);
        $storage->expects(self::once())
            ->method('writeStream')
            ->with(
                $destinationKey,
                self::callback(static fn (mixed $stream): bool => \is_resource($stream)
                    && $contents === stream_get_contents($stream)),
            );

        $service = new StagedFileService(
            $this->createStub(StagedFileRepositoryInterface::class),
            $storage,
            new TenantContext(),
        );
        $staged = new StagedFile(Uuid::v7(), 'source.xlsx', \strlen($contents), $sourceKey);

        $service->copyToKey($staged, $destinationKey);

        self::assertFalse(\is_resource($source), 'fallback stream must always be closed');
    }
}
