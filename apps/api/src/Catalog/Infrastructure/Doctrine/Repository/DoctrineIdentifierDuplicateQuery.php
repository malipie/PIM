<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Repository\IdentifierDuplicateQueryInterface;
use Doctrine\DBAL\Connection;

final readonly class DoctrineIdentifierDuplicateQuery implements IdentifierDuplicateQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function isDuplicate(CatalogObject $object, Attribute $attribute, string $value): bool
    {
        $tenant = $object->getTenant();
        if (null === $tenant) {
            return false;
        }

        $found = $this->connection->fetchOne(
            'SELECT 1 FROM object_values'
            .' WHERE tenant_id = :tenant'
            .' AND identifier_object_type_id = :objectType'
            .' AND attribute_id = :attribute'
            .' AND identifier_value = :value'
            .' AND object_id <> :currentObject'
            .' LIMIT 1',
            [
                'tenant' => $tenant->getId()->toRfc4122(),
                'objectType' => $object->getObjectType()->getId()->toRfc4122(),
                'attribute' => $attribute->getId()->toRfc4122(),
                'value' => $value,
                'currentObject' => $object->getId()->toRfc4122(),
            ],
        );

        return false !== $found;
    }
}
