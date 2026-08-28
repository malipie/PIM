<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\CreateAttribute;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeGroupAttribute;
use App\Catalog\Domain\Repository\AttributeGroupRepositoryInterface;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Validator\RelationAttributeConfigValidator;
use App\Shared\Application\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * VIEW-02 (#374) — creates a tenant-scoped Attribute. Mirrors the
 * CreateAttributeGroupHandler (#260) pattern: tenant guard, code
 * uniqueness check, then save through the repository which flushes
 * the EM under the hood (single attribute create path).
 *
 * VIEW-03 (#375) — when `attachToGroups` is non-empty, the handler
 * also creates AttributeGroupAttribute junction rows for each listed
 * group code in the same flush. Unknown group codes raise 422 before
 * any data is persisted (transactional all-or-nothing for the popup
 * „Stwórz nowy" UX in the FE).
 */
#[AsMessageHandler]
final readonly class CreateAttributeHandler
{
    public function __construct(
        private AttributeRepositoryInterface $repository,
        private AttributeGroupRepositoryInterface $groupRepository,
        private EntityManagerInterface $em,
        private TenantContext $tenantContext,
        private RelationAttributeConfigValidator $relationConfigValidator,
    ) {
    }

    public function __invoke(CreateAttributeCommand $command): Uuid
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new LogicException('Cannot create Attribute without an authenticated tenant.');
        }
        if (null !== $this->repository->findByCode($command->code, $tenant)) {
            throw new ConflictHttpException(\sprintf(
                'Attribute with code "%s" already exists for this tenant.',
                $command->code,
            ));
        }

        // Pre-validate group codes before any persistence so the create
        // either fully succeeds or no row is touched.
        $groups = [];
        foreach ($command->attachToGroups as $groupCode) {
            $group = $this->groupRepository->findByCode($groupCode, $tenant);
            if (null === $group) {
                throw new UnprocessableEntityHttpException(\sprintf(
                    'AttributeGroup "%s" was not found in this tenant.',
                    $groupCode,
                ));
            }
            $groups[] = $group;
        }

        $type = AttributeType::from($command->type);

        // ADR-014 / MOD-05 (#897) — relation-type attributes carry three
        // extra config columns. Validator enforces target ObjectType
        // existence, cardinality required, and coerces away half-set
        // payloads on non-relation types.
        [$relationTargetIds, $relationCardinality, $relationAdvanced] = $this->relationConfigValidator->validateAndNormalise(
            $type,
            $command->relationTargetObjectTypeIds,
            $command->relationCardinality,
            $command->relationAdvanced,
            $tenant,
        );

        $attribute = new Attribute(
            code: $command->code,
            label: $command->label,
            type: $type,
        );
        // #1179 — an identifier (EAN/GTIN/SKU) must resolve to exactly one
        // value per object so its per-ObjectType uniqueness scope stays
        // unambiguous; it never varies by locale or channel.
        $isIdentifier = AttributeType::Identifier === $type;

        $attribute->updateHelp($command->help);
        $attribute->changeLocalizable(!$isIdentifier && $command->localizable);
        $attribute->changeScopable(!$isIdentifier && $command->scopable);
        $attribute->changeRequired($command->required);
        $attribute->changeFilterable($command->filterable);
        $attribute->updateValidationRules($command->validationRules);
        $attribute->reorder($command->position);
        $attribute->setRelationTargetObjectTypeIds($relationTargetIds);
        $attribute->setRelationCardinality($relationCardinality);
        $attribute->setRelationAdvanced($relationAdvanced);

        $this->repository->save($attribute);

        // Junction rows go through the EM directly — repository->save()
        // already flushed once but the M:N attaches are cohesive with
        // the create from the operator's POV (single API call).
        foreach ($groups as $group) {
            $maxRow = $this->em->getConnection()->fetchOne(
                'SELECT COALESCE(MAX(position), -1) FROM attribute_group_attributes WHERE attribute_group_id = ?',
                [$group->getId()->toRfc4122()],
            );
            $position = (\is_scalar($maxRow) ? (int) $maxRow : -1) + 1;
            $junction = new AttributeGroupAttribute(
                attributeGroup: $group,
                attribute: $attribute,
                position: $position,
            );
            $this->em->persist($junction);
        }
        if ([] !== $groups) {
            $this->em->flush();
        }

        return $attribute->getId();
    }
}
