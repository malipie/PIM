<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\GetObjectTypeSummary;

use App\Catalog\Contracts\Query\ObjectTypeSummary;
use App\Catalog\Contracts\Query\ObjectTypeSummaryPort;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use LogicException;
use Symfony\Component\Uid\Uuid;

final readonly class GetObjectTypeSummaryHandler implements ObjectTypeSummaryPort
{
    public function __construct(
        private ObjectTypeRepositoryInterface $repository,
    ) {
    }

    public function __invoke(GetObjectTypeSummaryQuery $query): ?ObjectTypeSummary
    {
        return $this->byId($query->objectTypeId);
    }

    public function byId(Uuid $objectTypeId): ?ObjectTypeSummary
    {
        $objectType = $this->repository->findById($objectTypeId);
        if (null === $objectType) {
            return null;
        }

        $tenant = $objectType->getTenant();
        if (null === $tenant) {
            throw new LogicException('ObjectType hydrated without a tenant — corrupt fixture or persistence bug.');
        }

        return new ObjectTypeSummary(
            id: $objectType->getId(),
            kind: $objectType->getKind(),
            code: $objectType->getCode(),
            label: $objectType->getLabel(),
            tenantId: $tenant->getId(),
            isBuiltIn: $objectType->isBuiltIn(),
        );
    }
}
