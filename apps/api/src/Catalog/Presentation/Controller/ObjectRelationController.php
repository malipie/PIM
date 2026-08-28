<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Controller;

use App\Catalog\Application\ObjectRelationService;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Service\EffectiveAttributeGroupResolver;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Shared\Application\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * ADR-014 / MOD-06 (#898) — REST endpoints exposing the object↔object
 * link junction (`object_relations`) created in MOD-02.
 *
 *   GET    /api/objects/{id}/relations
 *   PUT    /api/objects/{id}/relations/{attributeCode}
 *   DELETE /api/objects/{id}/relations/{attributeCode}/{targetId}
 *
 * Tenant scope: every read goes through TenantFilter-bound repositories,
 * so a cross-tenant source id returns 404 immediately. The PUT body
 * carries a list of target `id` strings (with optional `metadata` once
 * MOD-08 lands; ignored for now).
 *
 * Cardinality + target ObjectType validation lives in
 * {@see ObjectRelationService}; this controller is a thin HTTP veneer.
 */
final class ObjectRelationController
{
    private const string UUID_REGEX = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    private const string CODE_REGEX = '[a-z][a-z0-9_]{0,63}';

    public function __construct(
        private readonly CatalogObjectRepositoryInterface $objects,
        private readonly AttributeRepositoryInterface $attributes,
        private readonly ObjectRelationService $service,
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $em,
        private readonly EffectiveAttributeGroupResolver $effectiveGroups,
    ) {
    }

    #[Route(
        '/api/objects/{id}/relations',
        name: 'pim_objects_relations_list',
        requirements: ['id' => self::UUID_REGEX],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'products', action: 'view')]
    public function list(string $id): JsonResponse
    {
        $source = $this->fetchObject($id);

        // We don't have a "find all relation attributes for an ObjectType"
        // shortcut here, so we walk the effective list of attributes via the
        // object_type_attributes junction (relation type only). For each
        // attribute, ask the service for its rows. The shape mirrors the FE
        // grouping in the MOD-12 tab.
        $relationAttributes = $this->relationAttributesForSource($source);

        $groups = [];
        foreach ($relationAttributes as $attribute) {
            $rows = $this->service->listForSource($source, $attribute);
            $groups[] = [
                'attribute' => [
                    'id' => $attribute->getId()->toRfc4122(),
                    'code' => $attribute->getCode(),
                    'label' => $attribute->getLabel(),
                    'cardinality' => $attribute->getRelationCardinality()?->value,
                    'advanced' => $attribute->isRelationAdvanced(),
                ],
                'relations' => array_map(
                    static fn ($row): array => [
                        'id' => $row->getId()->toRfc4122(),
                        'targetObjectId' => $row->getTarget()->getId()->toRfc4122(),
                        'position' => $row->getPosition(),
                        'metadata' => $row->getMetadata(),
                    ],
                    $rows,
                ),
            ];
        }

        return new JsonResponse([
            'sourceObjectId' => $source->getId()->toRfc4122(),
            'relationAttributes' => $groups,
        ]);
    }

    #[Route(
        '/api/objects/{id}/relations/{attributeCode}',
        name: 'pim_objects_relations_put',
        requirements: ['id' => self::UUID_REGEX, 'attributeCode' => self::CODE_REGEX],
        methods: ['PUT'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'products', action: 'edit')]
    public function put(string $id, string $attributeCode, Request $request): JsonResponse
    {
        $source = $this->fetchObject($id);
        $attribute = $this->fetchAttribute($attributeCode);

        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            throw new BadRequestHttpException('Body must be a JSON object.');
        }
        $rawTargets = $payload['targets'] ?? null;
        if (!\is_array($rawTargets)) {
            throw new BadRequestHttpException('Field "targets" must be an array of `{id}` objects (or UUID strings).');
        }

        /** @var list<array{id: Uuid, metadata: array<string, mixed>}> $targets */
        $targets = [];
        foreach ($rawTargets as $entry) {
            $rawId = \is_array($entry) ? ($entry['id'] ?? null) : $entry;
            if (!\is_string($rawId) || !Uuid::isValid($rawId)) {
                throw new BadRequestHttpException('Each target must carry a valid UUID `id`.');
            }
            $metadata = [];
            if (\is_array($entry) && isset($entry['metadata'])) {
                $rawMetadata = $entry['metadata'];
                if (!\is_array($rawMetadata)) {
                    throw new BadRequestHttpException('Field "metadata" must be a JSON object.');
                }
                /** @var array<string, mixed> $rawMetadata */
                $metadata = $rawMetadata;
            }
            $targets[] = ['id' => Uuid::fromString($rawId), 'metadata' => $metadata];
        }

        $this->service->replaceForSourceAndAttribute($source, $attribute, $targets);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * MODR-06 (#928) — lightweight count of reverse relations. Returned
     * alongside a boolean so the frontend can decide whether to show the
     * "Powiązania" tab when an object has no forward relation attributes
     * but is still pointed-at from elsewhere. The route is declared
     * *before* the heavy `reverse` route below so the more specific path
     * wins in the Symfony router.
     */
    #[Route(
        '/api/objects/{id}/relations/reverse/count',
        name: 'pim_objects_relations_reverse_count',
        requirements: ['id' => self::UUID_REGEX],
        methods: ['GET'],
        priority: 10,
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'products', action: 'view')]
    public function reverseCount(string $id): JsonResponse
    {
        $target = $this->fetchObject($id);
        $count = $this->service->countByTarget($target);

        return new JsonResponse([
            'targetObjectId' => $target->getId()->toRfc4122(),
            'count' => $count,
            'hasReverse' => $count > 0,
        ]);
    }

    #[Route(
        '/api/objects/{id}/relations/reverse',
        name: 'pim_objects_relations_reverse',
        requirements: ['id' => self::UUID_REGEX],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'products', action: 'view')]
    public function reverse(string $id): JsonResponse
    {
        $target = $this->fetchObject($id);
        $rows = $this->service->findByTarget($target);

        // Group by (source ObjectType, attribute) — UI renders read-only
        // "powiązania zwrotne" panel split by section.
        $groups = [];
        foreach ($rows as $row) {
            $attribute = $row->getAttribute();
            $source = $row->getSource();
            $key = $source->getObjectType()->getId()->toRfc4122().':'.$attribute->getId()->toRfc4122();
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'sourceObjectType' => [
                        'id' => $source->getObjectType()->getId()->toRfc4122(),
                        'code' => $source->getObjectType()->getCode(),
                        'kind' => $source->getObjectType()->getKind()->value,
                    ],
                    'attribute' => [
                        'id' => $attribute->getId()->toRfc4122(),
                        'code' => $attribute->getCode(),
                        'label' => $attribute->getLabel(),
                    ],
                    'sources' => [],
                ];
            }
            $groups[$key]['sources'][] = [
                'id' => $source->getId()->toRfc4122(),
                'code' => $source->getCode(),
                'relationId' => $row->getId()->toRfc4122(),
                'position' => $row->getPosition(),
            ];
        }

        return new JsonResponse([
            'targetObjectId' => $target->getId()->toRfc4122(),
            'reverseRelations' => array_values($groups),
        ]);
    }

    #[Route(
        '/api/objects/{id}/relations/{attributeCode}/{targetId}',
        name: 'pim_objects_relations_delete',
        requirements: [
            'id' => self::UUID_REGEX,
            'attributeCode' => self::CODE_REGEX,
            'targetId' => self::UUID_REGEX,
        ],
        methods: ['DELETE'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'products', action: 'edit')]
    public function delete(string $id, string $attributeCode, string $targetId): JsonResponse
    {
        $source = $this->fetchObject($id);
        $attribute = $this->fetchAttribute($attributeCode);
        $target = $this->fetchObject($targetId);

        // Idempotent: missing row is success (204). Matches the rest of
        // the catalog DELETE conventions.
        $this->service->removeOne($source, $attribute, $target);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function fetchObject(string $rawId): \App\Catalog\Domain\Entity\CatalogObject
    {
        $object = $this->objects->findById(Uuid::fromString($rawId));
        if (null === $object) {
            throw new NotFoundHttpException(\sprintf('Object "%s" was not found.', $rawId));
        }

        return $object;
    }

    private function fetchAttribute(string $code): Attribute
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new NotFoundHttpException('Tenant context not set.');
        }
        $attribute = $this->attributes->findByCode($code, $tenant);
        if (null === $attribute) {
            throw new NotFoundHttpException(\sprintf('Attribute "%s" was not found.', $code));
        }

        return $attribute;
    }

    /**
     * Collect every relation-type attribute effective for the source
     * object, from BOTH attachment paths:
     *
     *   1. via an AttributeGroup attached to the ObjectType (or inherited
     *      from the object's category tree) — resolved through the shared
     *      {@see EffectiveAttributeGroupResolver} so this matches exactly
     *      what the detail page renders as tabs/sections, and
     *   2. directly via the `object_type_attributes` junction.
     *
     * #1362 — the previous implementation looked only at path 2, so a
     * relation attribute attached through a group (the normal modeling
     * flow) surfaced as "not attached to ObjectType" in the inline editor
     * and the operator could not add links. Group order then position is
     * preserved; direct attributes follow. Deduplicated by attribute id.
     *
     * @return list<Attribute>
     */
    private function relationAttributesForSource(\App\Catalog\Domain\Entity\CatalogObject $source): array
    {
        $relationAttributes = [];
        $seen = [];

        $add = static function (Attribute $attribute) use (&$relationAttributes, &$seen): void {
            if (AttributeType::Relation !== $attribute->getType()) {
                return;
            }
            $key = $attribute->getId()->toRfc4122();
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $relationAttributes[] = $attribute;
        };

        // Path 1 — group-attached (incl. category inheritance).
        $groups = $this->effectiveGroups->resolve($source);
        $attributesByGroup = $this->effectiveGroups->loadGroupAttributes($groups);
        foreach ($groups as $group) {
            foreach ($attributesByGroup[$group->getId()->toRfc4122()] ?? [] as $junction) {
                $add($junction->getAttribute());
            }
        }

        // Path 2 — directly attached via object_type_attributes.
        /** @var list<Attribute> $direct */
        $direct = $this->em
            ->createQuery(
                'SELECT a FROM '.Attribute::class.' a'
                .' JOIN '.ObjectTypeAttribute::class.' j WITH j.attribute = a'
                .' WHERE j.objectType = :type AND a.type = :type_value'
                .' ORDER BY j.sortOrder ASC, a.code ASC'
            )
            ->setParameter('type', $source->getObjectType())
            ->setParameter('type_value', AttributeType::Relation->value)
            ->getResult();
        foreach ($direct as $attribute) {
            $add($attribute);
        }

        return $relationAttributes;
    }
}
