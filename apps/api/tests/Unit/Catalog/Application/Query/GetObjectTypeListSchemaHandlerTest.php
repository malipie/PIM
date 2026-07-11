<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\Query;

use App\Catalog\Application\Query\GetObjectTypeListSchema\GetObjectTypeListSchemaHandler;
use App\Catalog\Application\Query\GetObjectTypeListSchema\GetObjectTypeListSchemaQuery;
use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeAttributeRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Catalog\Domain\Service\EffectiveAttributeGroupResolver;
use Doctrine\ORM\EntityManagerInterface;
use App\Catalog\Domain\Repository\ObjectCategoryRepositoryInterface;
use App\Identity\Contracts\Policy\AttributePermissionReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * ULV-03 (#984) — handler covers the universal list schema composition:
 * system columns always render, attribute columns filter on
 * `show_in_list` and sort by `list_position`, filterable/searchable
 * lists derive from per-attribute flags + type.
 */
final class GetObjectTypeListSchemaHandlerTest extends TestCase
{
    #[Test]
    public function returnsNullForUnknownObjectType(): void
    {
        $handler = new GetObjectTypeListSchemaHandler(
            $this->repositoryReturning(null),
            $this->junctionRepositoryReturning([]),
            $this->permissionsAllow(),
            $this->groupResolver(),
        );

        $result = $handler(new GetObjectTypeListSchemaQuery(Uuid::v7()));

        self::assertNull($result);
    }

    #[Test]
    public function emitsFourSystemColumnsEvenWhenNoAttributesShownInList(): void
    {
        $objectType = $this->makeObjectType();
        $handler = new GetObjectTypeListSchemaHandler(
            $this->repositoryReturning($objectType),
            $this->junctionRepositoryReturning([]),
            $this->permissionsAllow(),
            $this->groupResolver(),
        );

        $schema = $handler(new GetObjectTypeListSchemaQuery($objectType->getId()));

        self::assertNotNull($schema);
        self::assertCount(4, $schema->columns);
        self::assertSame('code', $schema->columns[0]['key']);
        self::assertSame('status', $schema->columns[1]['key']);
        self::assertSame('completeness', $schema->columns[2]['key']);
        self::assertSame('updatedAt', $schema->columns[3]['key']);
        foreach ($schema->columns as $column) {
            self::assertTrue($column['system']);
        }
    }

    #[Test]
    public function shownInListAttributesOrderedByListPositionThenCode(): void
    {
        $objectType = $this->makeObjectType();
        $name = $this->makeJunction($objectType, 'name', AttributeType::Text, showInList: true, listPosition: 2);
        $sku = $this->makeJunction($objectType, 'sku', AttributeType::Text, showInList: true, listPosition: 1);
        $hidden = $this->makeJunction($objectType, 'description', AttributeType::Text, showInList: false, listPosition: 0);
        // Two attributes with the same listPosition resolve by code asc.
        $color = $this->makeJunction($objectType, 'color', AttributeType::Text, showInList: true, listPosition: 3);
        $brand = $this->makeJunction($objectType, 'brand', AttributeType::Text, showInList: true, listPosition: 3);

        $handler = new GetObjectTypeListSchemaHandler(
            $this->repositoryReturning($objectType),
            $this->junctionRepositoryReturning([$name, $sku, $hidden, $color, $brand]),
            $this->permissionsAllow(),
            $this->groupResolver(),
        );

        $schema = $handler(new GetObjectTypeListSchemaQuery($objectType->getId()));

        self::assertNotNull($schema);
        $attributeKeys = array_column(
            array_filter($schema->columns, static fn ($c) => !$c['system']),
            'key',
        );
        self::assertSame(['sku', 'name', 'brand', 'color'], $attributeKeys);
    }

    #[Test]
    public function filterableAttributesAreOnlyTheFlaggedOnes(): void
    {
        $objectType = $this->makeObjectType();
        $filterable = $this->makeJunction($objectType, 'brand', AttributeType::Text, showInList: false, filterable: true);
        $notFilterable = $this->makeJunction($objectType, 'note', AttributeType::Text, showInList: false, filterable: false);

        $handler = new GetObjectTypeListSchemaHandler(
            $this->repositoryReturning($objectType),
            $this->junctionRepositoryReturning([$filterable, $notFilterable]),
            $this->permissionsAllow(),
            $this->groupResolver(),
        );

        $schema = $handler(new GetObjectTypeListSchemaQuery($objectType->getId()));

        self::assertNotNull($schema);
        self::assertSame(['brand'], $schema->filterableAttributes);
    }

    #[Test]
    public function searchableSubsetCoversTextAndWysiwygOnly(): void
    {
        $objectType = $this->makeObjectType();
        $text = $this->makeJunction($objectType, 'name', AttributeType::Text, filterable: true);
        $wysiwyg = $this->makeJunction($objectType, 'long_desc', AttributeType::Wysiwyg, filterable: true);
        $number = $this->makeJunction($objectType, 'weight', AttributeType::Number, filterable: true);

        $handler = new GetObjectTypeListSchemaHandler(
            $this->repositoryReturning($objectType),
            $this->junctionRepositoryReturning([$text, $wysiwyg, $number]),
            $this->permissionsAllow(),
            $this->groupResolver(),
        );

        $schema = $handler(new GetObjectTypeListSchemaQuery($objectType->getId()));

        self::assertNotNull($schema);
        self::assertContains('name', $schema->searchableAttributes);
        self::assertContains('long_desc', $schema->searchableAttributes);
        self::assertNotContains('weight', $schema->searchableAttributes);
    }

    #[Test]
    public function hidesRestrictedAttributeFromColumnsAndFilters(): void
    {
        $objectType = $this->makeObjectType();
        $visible = $this->makeJunction($objectType, 'name', AttributeType::Text, showInList: true, filterable: true);
        $restricted = $this->makeJunction($objectType, 'margin', AttributeType::Number, showInList: true, filterable: true);
        $restrictedId = $restricted->getAttribute()->getId()->toRfc4122();

        $handler = new GetObjectTypeListSchemaHandler(
            $this->repositoryReturning($objectType),
            $this->junctionRepositoryReturning([$visible, $restricted]),
            $this->permissionsRestrictByCode($restrictedId),
        );

        $schema = $handler(new GetObjectTypeListSchemaQuery($objectType->getId()));

        self::assertNotNull($schema);
        $attributeKeys = array_column(
            array_filter($schema->columns, static fn ($c) => !$c['system']),
            'key',
        );
        self::assertSame(['name'], $attributeKeys, 'restricted attribute removed from columns');
        self::assertNotContains('margin', $schema->filterableAttributes);
        self::assertContains('name', $schema->filterableAttributes);
    }

    #[Test]
    public function objectTypeHeaderExposesCapabilityFlagsForUlv09(): void
    {
        $objectType = $this->makeObjectType();
        $objectType->setCategorizable(true);
        $objectType->setHasVariants(true);
        $objectType->setExposeToMainMenu(true);

        $handler = new GetObjectTypeListSchemaHandler(
            $this->repositoryReturning($objectType),
            $this->junctionRepositoryReturning([]),
            $this->permissionsAllow(),
            $this->groupResolver(),
        );

        $schema = $handler(new GetObjectTypeListSchemaQuery($objectType->getId()));

        self::assertNotNull($schema);
        self::assertTrue($schema->objectType['is_categorizable']);
        self::assertTrue($schema->objectType['has_variants']);
        self::assertTrue($schema->objectType['expose_to_main_menu']);
    }

    private function permissionsAllow(): AttributePermissionReader
    {
        return new class implements AttributePermissionReader {
            public function canViewAttribute(Uuid $attributeId): bool
            {
                return true;
            }

            public function canEditAttribute(Uuid $attributeId): bool
            {
                return true;
            }

            public function isAttributePermissionEnforced(): bool
            {
                return true;
            }
        };
    }

    private function permissionsRestrictByCode(string $restrictedCode): AttributePermissionReader
    {
        $blockedCode = $restrictedCode;

        return new class($blockedCode) implements AttributePermissionReader {
            public function __construct(private readonly string $code)
            {
            }

            public function canViewAttribute(Uuid $attributeId): bool
            {
                return $this->code !== $attributeId->toRfc4122();
            }

            public function canEditAttribute(Uuid $attributeId): bool
            {
                return $this->code !== $attributeId->toRfc4122();
            }

            public function isAttributePermissionEnforced(): bool
            {
                return true;
            }
        };
    }

    private function makeObjectType(): ObjectType
    {
        return new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkty']);
    }

    private function makeJunction(
        ObjectType $objectType,
        string $code,
        AttributeType $type,
        bool $showInList = false,
        int $listPosition = 0,
        bool $filterable = false,
    ): ObjectTypeAttribute {
        $attribute = new Attribute($code, ['pl' => ucfirst($code)], $type);
        $attribute->changeFilterable($filterable);

        $junction = new ObjectTypeAttribute($objectType, $attribute);
        $junction->setShowInList($showInList);
        $junction->setListPosition($listPosition);

        return $junction;
    }

    private function repositoryReturning(?ObjectType $objectType): ObjectTypeRepositoryInterface
    {
        return new class($objectType) implements ObjectTypeRepositoryInterface {
            public function __construct(private readonly ?ObjectType $row)
            {
            }

            public function findById(Uuid $id): ?ObjectType
            {
                return $this->row;
            }

            public function findByCode(string $code, \App\Shared\Domain\Tenant $tenant): ?ObjectType
            {
                return null;
            }

            public function findByKind(ObjectKind $kind, \App\Shared\Domain\Tenant $tenant): array
            {
                return [];
            }

            public function findAllByTenant(\App\Shared\Domain\Tenant $tenant): array
            {
                return [];
            }

            public function findBuiltInByKind(ObjectKind $kind, \App\Shared\Domain\Tenant $tenant): ?ObjectType
            {
                return null;
            }

            public function save(ObjectType $objectType): void
            {
            }

            public function remove(ObjectType $objectType): void
            {
            }
        };
    }

    /**
     * @param list<ObjectTypeAttribute> $junctions
     */
    private function junctionRepositoryReturning(array $junctions): ObjectTypeAttributeRepositoryInterface
    {
        return new class($junctions) implements ObjectTypeAttributeRepositoryInterface {
            /** @param list<ObjectTypeAttribute> $rows */
            public function __construct(private readonly array $rows)
            {
            }

            public function findByObjectType(ObjectType $objectType): array
            {
                return $this->rows;
            }

            public function findByAttribute(Attribute $attribute): array
            {
                return [];
            }

            public function findOne(ObjectType $objectType, Attribute $attribute): ?ObjectTypeAttribute
            {
                return null;
            }

            public function save(ObjectTypeAttribute $junction): void
            {
            }

            public function remove(ObjectTypeAttribute $junction): void
            {
            }
        };
    }

    /**
     * GRID-P3-01 — real resolver over mocked ports; only invoked in
     * `full` mode, which these unit cases do not exercise.
     */
    private function groupResolver(): EffectiveAttributeGroupResolver
    {
        return new EffectiveAttributeGroupResolver(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(ObjectCategoryRepositoryInterface::class),
        );
    }
}
