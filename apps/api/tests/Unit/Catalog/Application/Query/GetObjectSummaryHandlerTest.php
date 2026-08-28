<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\Query;

use App\Catalog\Application\Query\GetObjectSummary\GetObjectSummaryHandler;
use App\Catalog\Application\Query\GetObjectSummary\GetObjectSummaryQuery;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Shared\Domain\Tenant;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class GetObjectSummaryHandlerTest extends TestCase
{
    #[Test]
    public function returnsNullForUnknownObjectId(): void
    {
        $repo = new InMemoryCatalogObjectRepo();
        $handler = new GetObjectSummaryHandler($repo);

        self::assertNull($handler(new GetObjectSummaryQuery(Uuid::v7())));
    }

    #[Test]
    public function projectsObjectIntoSummaryDto(): void
    {
        $tenant = new Tenant('demo', 'Demo');
        $type = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt']);
        $type->assignTenant($tenant);

        $object = new CatalogObject($type, 'PROD-001');
        $object->assignTenant($tenant);

        $repo = new InMemoryCatalogObjectRepo();
        $repo->store($object);

        $summary = (new GetObjectSummaryHandler($repo))(new GetObjectSummaryQuery($object->getId()));

        self::assertNotNull($summary);
        self::assertSame($object->getId(), $summary->id);
        self::assertSame(ObjectKind::Product, $summary->kind);
        self::assertSame('PROD-001', $summary->code);
        self::assertSame($tenant->getId(), $summary->tenantId);
        self::assertNull($summary->parentId);
    }

    #[Test]
    public function projectsLabelFromAttributesIndexedWhenLabelAttributeIsSet(): void
    {
        $tenant = new Tenant('demo', 'Demo');
        $name = new Attribute('name', ['pl' => 'Nazwa'], AttributeType::Text);
        $name->assignTenant($tenant);

        $type = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt']);
        $type->assignTenant($tenant);
        $type->assignLabelAttribute($name);

        $object = new CatalogObject($type, 'PROD-002');
        $object->assignTenant($tenant);
        $object->updateAttributeIndex(['name' => ['pl' => 'Buty', 'en' => 'Shoes']]);

        $repo = new InMemoryCatalogObjectRepo();
        $repo->store($object);

        $summary = (new GetObjectSummaryHandler($repo))(new GetObjectSummaryQuery($object->getId()));

        self::assertNotNull($summary);
        self::assertSame(['pl' => 'Buty', 'en' => 'Shoes'], $summary->label);
    }
}

/**
 * Minimal in-memory repository so the handler can be exercised without booting
 * the kernel. Only `findById` is used; everything else is unsupported.
 */
final class InMemoryCatalogObjectRepo implements CatalogObjectRepositoryInterface
{
    /** @var array<string, CatalogObject> */
    private array $objects = [];

    public function store(CatalogObject $object): void
    {
        $this->objects[$object->getId()->toRfc4122()] = $object;
    }

    public function findById(Uuid $id): ?CatalogObject
    {
        return $this->objects[$id->toRfc4122()] ?? null;
    }

    public function findByIds(array $idsRfc4122): array
    {
        throw new LogicException('not used in this test');
    }

    public function findByCode(string $code, ObjectKind $kind, Tenant $tenant): ?CatalogObject
    {
        throw new LogicException('not used in this test');
    }

    public function findByCodeInObjectTypes(string $code, array $objectTypeIds, Tenant $tenant): ?CatalogObject
    {
        throw new LogicException('not used in this test');
    }

    public function findChildrenByParentIds(array $parentIdsRfc4122, Tenant $tenant): array
    {
        throw new LogicException('not used in this test');
    }

    public function findByKind(ObjectKind $kind, Tenant $tenant): array
    {
        throw new LogicException('not used in this test');
    }

    public function findByObjectType(ObjectType $objectType, Tenant $tenant): array
    {
        throw new LogicException('not used in this test');
    }

    public function findRootObjectsAfter(ObjectType $objectType, Tenant $tenant, ?Uuid $afterId, int $limit): array
    {
        throw new LogicException('not used in this test');
    }

    public function countRootObjectsByType(ObjectType $objectType, Tenant $tenant): int
    {
        throw new LogicException('not used in this test');
    }

    public function findRootObjectIds(ObjectType $objectType, Tenant $tenant): array
    {
        throw new LogicException('not used in this test');
    }

    public function filterRootObjectIds(array $idsRfc4122, Tenant $tenant): array
    {
        throw new LogicException('not used in this test');
    }

    public function findChildIdsByParentIds(array $parentIdsRfc4122, Tenant $tenant): array
    {
        throw new LogicException('not used in this test');
    }

    public function save(CatalogObject $object): void
    {
        throw new LogicException('not used in this test');
    }

    public function remove(CatalogObject $object): void
    {
        throw new LogicException('not used in this test');
    }
}
