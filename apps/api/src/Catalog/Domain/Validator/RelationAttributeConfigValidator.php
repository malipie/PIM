<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Validator;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\RelationCardinality;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Domain\Tenant;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * ADR-014 / MOD-05 (#897) — validates the three relation-specific config
 * columns on an Attribute of type `relation`:
 *
 *  - `relation_target_object_type_ids` (JSONB list of UUIDs) — every id
 *    must resolve to an ObjectType in the current tenant. An empty list
 *    is allowed at the data layer but the validator returns warning-free
 *    null because UI gates it (operator-facing constraint).
 *  - `relation_cardinality` (`one` / `many`) — MUST be set on `relation`
 *    attributes; MUST be null on every other type.
 *  - `relation_advanced` — boolean, no cross-field rule.
 *
 * For non-relation attribute types, the validator coerces the three
 * fields back to their defaults (empty list, null, false) so the
 * persistence layer never carries half-set relation config on
 * `text`/`select`/etc. rows.
 */
final readonly class RelationAttributeConfigValidator
{
    public function __construct(
        private ObjectTypeRepositoryInterface $objectTypes,
    ) {
    }

    /**
     * @param list<string> $targetObjectTypeIds
     *
     * @return array{0: list<string>, 1: ?RelationCardinality, 2: bool}
     *                                                                  tuple of the sanitised (targetIds, cardinality, advanced)
     */
    public function validateAndNormalise(
        AttributeType $type,
        array $targetObjectTypeIds,
        ?string $cardinality,
        bool $advanced,
        Tenant $tenant,
    ): array {
        if (AttributeType::Relation !== $type) {
            if ([] !== $targetObjectTypeIds || null !== $cardinality || $advanced) {
                throw new UnprocessableEntityHttpException(
                    'Relation config columns are only meaningful for attributes of type "relation".',
                );
            }

            return [[], null, false];
        }

        if (null === $cardinality) {
            throw new UnprocessableEntityHttpException(
                'Attribute of type "relation" requires `relationCardinality` to be set to "one" or "many".',
            );
        }

        $resolvedCardinality = RelationCardinality::tryFrom($cardinality);
        if (null === $resolvedCardinality) {
            throw new UnprocessableEntityHttpException(\sprintf(
                '"%s" is not a valid relationCardinality (expected "one" or "many").',
                $cardinality,
            ));
        }

        $normalisedIds = [];
        foreach ($targetObjectTypeIds as $raw) {
            if (!Uuid::isValid($raw)) {
                throw new UnprocessableEntityHttpException(\sprintf(
                    '"%s" is not a valid UUID in relationTargetObjectTypeIds.',
                    $raw,
                ));
            }
            $uuid = Uuid::fromString($raw);
            $candidate = $this->objectTypes->findById($uuid);
            if (null === $candidate) {
                throw new UnprocessableEntityHttpException(\sprintf(
                    'ObjectType "%s" referenced in relationTargetObjectTypeIds was not found in tenant "%s".',
                    $raw,
                    $tenant->getCode(),
                ));
            }
            if ($candidate->getTenant()?->getId()->equals($tenant->getId()) !== true) {
                throw new UnprocessableEntityHttpException(\sprintf(
                    'ObjectType "%s" referenced in relationTargetObjectTypeIds belongs to a different tenant.',
                    $raw,
                ));
            }
            $normalisedIds[$uuid->toRfc4122()] = $uuid->toRfc4122();
        }

        return [array_values($normalisedIds), $resolvedCardinality, $advanced];
    }
}
