<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Contracts\Query\ObjectTypeAttributeCodesPort;
use App\Catalog\Domain\Repository\ObjectTypeAttributeRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * #2943 — Catalog-side adapter for {@see ObjectTypeAttributeCodesPort}.
 *
 * Reads the same `object_type_attributes` junction the modeling UI and the
 * import mapping step read, so "does this type model `sku`" gets the same
 * answer everywhere.
 */
final readonly class ObjectTypeAttributeCodesReader implements ObjectTypeAttributeCodesPort
{
    public function __construct(
        private ObjectTypeRepositoryInterface $objectTypes,
        private ObjectTypeAttributeRepositoryInterface $junctions,
    ) {
    }

    public function forObjectType(Uuid $objectTypeId): array
    {
        $objectType = $this->objectTypes->findById($objectTypeId);
        if (null === $objectType) {
            return [];
        }

        $codes = [];
        foreach ($this->junctions->findByObjectType($objectType) as $junction) {
            $codes[] = $junction->getAttribute()->getCode();
        }

        return $codes;
    }
}
