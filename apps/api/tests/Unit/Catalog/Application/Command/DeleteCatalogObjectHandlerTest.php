<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\Command;

use App\Catalog\Application\Command\DeleteCatalogObject\DeleteCatalogObjectCommand;
use App\Catalog\Application\Command\DeleteCatalogObject\DeleteCatalogObjectHandler;
use App\Catalog\Application\Reindex\BulkReindexQueueInterface;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

final class DeleteCatalogObjectHandlerTest extends TestCase
{
    #[Test]
    public function removesTheSourceRowAndQueuesAnExplicitSearchDelete(): void
    {
        $object = new CatalogObject(
            new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt']),
            'DELETE-ME-2986',
        );

        $catalogObjects = $this->createMock(CatalogObjectRepositoryInterface::class);
        $catalogObjects->expects(self::once())
            ->method('findById')
            ->with($object->getId())
            ->willReturn($object);
        $catalogObjects->expects(self::once())
            ->method('remove')
            ->with($object);

        $reindexQueue = $this->createMock(BulkReindexQueueInterface::class);
        $reindexQueue->expects(self::once())
            ->method('queueAllDeleted')
            ->with([$object->getId()->toRfc4122()], ObjectKind::Product);

        $handler = new DeleteCatalogObjectHandler($catalogObjects, $reindexQueue);
        $handler(new DeleteCatalogObjectCommand($object->getId()));
    }

    #[Test]
    public function unknownObjectDoesNotQueueADelete(): void
    {
        $id = Uuid::v7();
        $catalogObjects = $this->createMock(CatalogObjectRepositoryInterface::class);
        $catalogObjects->expects(self::once())
            ->method('findById')
            ->with($id)
            ->willReturn(null);
        $catalogObjects->expects(self::never())->method('remove');

        $reindexQueue = $this->createMock(BulkReindexQueueInterface::class);
        $reindexQueue->expects(self::never())->method('queueAllDeleted');

        $handler = new DeleteCatalogObjectHandler($catalogObjects, $reindexQueue);

        $this->expectException(NotFoundHttpException::class);
        $handler(new DeleteCatalogObjectCommand($id));
    }
}
