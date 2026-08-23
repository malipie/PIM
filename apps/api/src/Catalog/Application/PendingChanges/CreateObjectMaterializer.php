<?php

declare(strict_types=1);

namespace App\Catalog\Application\PendingChanges;

use App\Catalog\Application\ValueWriteCore;
use App\Catalog\Contracts\Command\CreateObjectProposal;
use App\Catalog\Contracts\Command\CreateObjectProposalPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeDraft;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Provenance;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeAttributeRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Identity\Contracts\Policy\UserScopedPermissionCheckerInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use LogicException;
use Symfony\Component\Uid\Uuid;

/** #2984 — pre-validates and materializes one object creation proposal. */
final readonly class CreateObjectMaterializer implements CreateObjectProposalPort
{
    public function __construct(
        private TenantContext $tenantContext,
        private ObjectTypeRepositoryInterface $objectTypes,
        private CatalogObjectRepositoryInterface $objects,
        private AttributeRepositoryInterface $attributes,
        private ObjectTypeAttributeRepositoryInterface $typeAttributes,
        private UserScopedPermissionCheckerInterface $permissions,
        private AgentValueNormalizer $valueNormalizer,
        private PendingChangesPort $pendingChanges,
    ) {
    }

    public function materializeObjectCreation(
        Uuid $batchId,
        Uuid $userId,
        string $objectTypeCode,
        string $code,
        array $attributes,
        ?string $parentId = null,
        array $categoryIds = [],
    ): CreateObjectProposal {
        $tenant = $this->tenantContext->get();
        if (!$tenant instanceof Tenant) {
            throw new LogicException('Cannot materialize object creation without a current tenant.');
        }

        $rejected = [];
        $objectType = $this->objectTypes->findByCode($objectTypeCode, $tenant);
        if (null === $objectType) {
            return $this->rejected($batchId, 'object_type_code', \sprintf('Unknown object type "%s".', $objectTypeCode));
        }
        if (ObjectKind::Category === $objectType->getKind()) {
            return $this->rejected($batchId, 'object_type_code', 'Category creation requires tree scope and is not supported by create_object.');
        }

        $code = trim($code);
        if ('' === $code || mb_strlen($code) > 128) {
            return $this->rejected($batchId, 'code', 'Code must contain 1-128 characters.');
        }
        if ($this->objects->findByCode($code, $objectType->getKind(), $tenant) instanceof CatalogObject) {
            return $this->rejected(
                $batchId,
                'code',
                \sprintf('Code "%s" already exists. Choose another code or use get_object to inspect the existing object.', $code),
            );
        }

        $parentUuid = $this->tenantObjectId($parentId, $tenant, 'parent_id', $rejected);
        $categoryUuids = [];
        foreach (array_values(array_unique($categoryIds)) as $categoryId) {
            $categoryUuid = $this->tenantObjectId($categoryId, $tenant, 'categories', $rejected, ObjectKind::Category);
            if ($categoryUuid instanceof Uuid) {
                $categoryUuids[] = $categoryUuid->toRfc4122();
            }
        }

        $normalised = [];
        foreach ($attributes as $attributeCode => $rawValue) {
            if ('' === trim($attributeCode)) {
                $rejected[] = ['field' => 'attributes', 'reason' => 'Every attribute key must be a non-empty code.'];
                continue;
            }
            $attribute = $this->attributes->findByCode($attributeCode, $tenant);
            if (!$attribute instanceof Attribute) {
                $rejected[] = ['field' => 'attributes.'.$attributeCode, 'reason' => 'Unknown attribute.'];
                continue;
            }
            if (!$this->permissions->canEditAttribute($userId, $attribute->getId())) {
                $rejected[] = ['field' => 'attributes.'.$attributeCode, 'reason' => 'Attribute is outside your edit permissions.'];
                continue;
            }
            [$envelope, $error] = $this->valueNormalizer->normalise($attribute, $rawValue);
            if (null === $envelope) {
                $rejected[] = ['field' => 'attributes.'.$attributeCode, 'reason' => $error ?? 'Invalid value.'];
                continue;
            }
            $normalised[$attributeCode] = $envelope;
        }

        foreach ($this->typeAttributes->findByObjectType($objectType) as $junction) {
            $attribute = $junction->getAttribute();
            if (!$attribute->isRequired()) {
                continue;
            }
            $envelope = $normalised[$attribute->getCode()] ?? null;
            if (null === $envelope || ValueWriteCore::isEmptyEnvelope($envelope)) {
                $rejected[] = [
                    'field' => 'attributes.'.$attribute->getCode(),
                    'reason' => \sprintf('Required attribute "%s" is missing.', $attribute->getCode()),
                ];
            }
        }

        if ([] !== $categoryUuids && !$objectType->isCategorizable()) {
            $rejected[] = ['field' => 'categories', 'reason' => \sprintf('Object type "%s" is not categorizable.', $objectTypeCode)];
        }
        if ([] !== $rejected) {
            return new CreateObjectProposal($batchId, false, $rejected);
        }

        $after = [
            'object_type_id' => $objectType->getId()->toRfc4122(),
            'object_type_code' => $objectType->getCode(),
            'kind' => $objectType->getKind()->value,
            'code' => $code,
            'parent_id' => $parentUuid?->toRfc4122(),
            'category_ids' => $categoryUuids,
            'primary_category_id' => $categoryUuids[0] ?? null,
            'attributes' => $normalised,
        ];
        $this->pendingChanges->materialize($batchId, Provenance::Agent->value, [
            new PendingChangeDraft(
                changeType: PendingChangeType::Object,
                after: $after,
                meta: ['confirmation' => ['code' => $code, 'object_type_code' => $objectTypeCode]],
            ),
        ]);

        return new CreateObjectProposal($batchId, true);
    }

    /** @param list<array{field: string, reason: string}> $rejected */
    private function tenantObjectId(
        ?string $rawId,
        Tenant $tenant,
        string $field,
        array &$rejected,
        ?ObjectKind $expectedKind = null,
    ): ?Uuid {
        if (null === $rawId) {
            return null;
        }
        if (!Uuid::isValid($rawId)) {
            $rejected[] = ['field' => $field, 'reason' => 'Expected a valid UUID.'];

            return null;
        }
        $id = Uuid::fromString($rawId);
        $object = $this->objects->findById($id);
        if (!$object instanceof CatalogObject
            || !$object->getTenant() instanceof Tenant
            || !$object->getTenant()->getId()->equals($tenant->getId())) {
            $rejected[] = ['field' => $field, 'reason' => 'Object not found in the current tenant.'];

            return null;
        }
        if (null !== $expectedKind && $expectedKind !== $object->getKind()) {
            $rejected[] = ['field' => $field, 'reason' => \sprintf('Object must be of kind "%s".', $expectedKind->value)];

            return null;
        }

        return $id;
    }

    private function rejected(Uuid $batchId, string $field, string $reason): CreateObjectProposal
    {
        return new CreateObjectProposal($batchId, false, [['field' => $field, 'reason' => $reason]]);
    }
}
