<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Controller;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\AttributeOptionRepositoryInterface;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeAttributeRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Catalog\Domain\Service\EffectiveAttributeGroupResolver;
use App\Identity\Contracts\Attribute\RequiresPermission;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

use const JSON_THROW_ON_ERROR;

/**
 * #891 — preview effective attribute groups for a hypothetical product
 * the operator is still composing in `/products/new`. The existing
 * `GET /api/products/{id}/effective-attribute-groups` only works for
 * persisted products; the preview lets the create form react to the
 * sidebar category picker without having to POST first.
 *
 * `POST /api/object_types/{id}/effective-attribute-groups/preview`
 * Body: `{"categoryIds": ["<uuid>", ...]}` (may be empty array).
 *
 * The response shape MIRRORS the persisted endpoint so the frontend
 * loader can stay identical: same `groups[].attributes[]` payload with
 * option payloads for select-like types and the synthetic "default"
 * bucket at the end for ObjectType-attached attributes outside any
 * declared group.
 *
 * Tenant scoping is enforced through the existing TenantFilter on the
 * `objects` table: ObjectType and every category UUID round-trip through
 * the tenant-scoped repository. A cross-tenant ObjectType or category
 * UUID resolves to `null` and triggers 404/422 respectively.
 */
final class ObjectTypeEffectiveGroupsPreviewController
{
    private const string UUID_REGEX = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    private const int MAX_CATEGORIES_PER_PREVIEW = 50;

    public function __construct(
        private readonly ObjectTypeRepositoryInterface $objectTypes,
        private readonly CatalogObjectRepositoryInterface $objects,
        private readonly EffectiveAttributeGroupResolver $resolver,
        private readonly ObjectTypeAttributeRepositoryInterface $objectTypeAttributes,
        private readonly AttributeOptionRepositoryInterface $attributeOptions,
    ) {
    }

    #[Route(
        '/api/object_types/{id}/effective-attribute-groups/preview',
        name: 'pim_object_types_effective_attribute_groups_preview',
        requirements: ['id' => self::UUID_REGEX],
        methods: ['POST'],
        priority: 200,
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    // #2881 — this is the create form's schema. Gating it on products.view
    // alone meant a role granted only "add a product" could POST an object
    // it had no way to compose: the form could not render. Reading the
    // schema follows reading OR creating the data it describes.
    #[RequiresPermission(module: 'products', action: 'view', anyOf: [
        'products.view',
        'products.add',
        'categories.add_edit',
        'multimedia.add_edit_own',
        'multimedia.add_edit_any',
        'object.add',
    ])]
    public function preview(string $id, Request $request): JsonResponse
    {
        try {
            $objectTypeId = Uuid::fromString($id);
        } catch (InvalidArgumentException $e) {
            throw new NotFoundHttpException(\sprintf('ObjectType "%s" was not found.', $id), $e);
        }
        $objectType = $this->objectTypes->findById($objectTypeId);
        if (null === $objectType) {
            throw new NotFoundHttpException(\sprintf('ObjectType "%s" was not found.', $id));
        }

        $payload = $this->decodePayload($request);
        $rawIds = $payload['categoryIds'] ?? [];
        if (!\is_array($rawIds)) {
            throw new UnprocessableEntityHttpException('Field "categoryIds" must be an array.');
        }
        if (\count($rawIds) > self::MAX_CATEGORIES_PER_PREVIEW) {
            throw new UnprocessableEntityHttpException(\sprintf(
                'At most %d categories per preview (got %d).',
                self::MAX_CATEGORIES_PER_PREVIEW,
                \count($rawIds),
            ));
        }

        $categories = [];
        $seen = [];
        foreach ($rawIds as $rawId) {
            if (!\is_string($rawId) || '' === $rawId) {
                throw new UnprocessableEntityHttpException('Each entry of "categoryIds" must be a UUID string.');
            }
            try {
                $categoryUuid = Uuid::fromString($rawId);
            } catch (InvalidArgumentException $e) {
                throw new UnprocessableEntityHttpException(\sprintf('"%s" is not a valid UUID.', $rawId), $e);
            }
            $key = $categoryUuid->toRfc4122();
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $category = $this->objects->findById($categoryUuid);
            if (null === $category) {
                throw new UnprocessableEntityHttpException(\sprintf('Category "%s" was not found.', $key));
            }
            if (ObjectKind::Category !== $category->getKind()) {
                throw new UnprocessableEntityHttpException(\sprintf('Object "%s" is not a category.', $key));
            }
            $categories[] = $category;
        }

        $groups = $this->resolver->resolveForCategoryList($objectType, $categories);
        $byGroup = $this->resolver->loadGroupAttributes($groups);
        $displayModes = $this->resolver->loadObjectTypeGroupDisplayModes($objectType);

        /** @var array<string, Attribute> $optionsAttrs */
        $optionsAttrs = [];
        foreach ($groups as $group) {
            foreach ($byGroup[$group->getId()->toRfc4122()] ?? [] as $j) {
                $a = $j->getAttribute();
                if ($a->getType()->usesOptions()) {
                    $optionsAttrs[$a->getId()->toRfc4122()] = $a;
                }
            }
        }
        foreach ($this->objectTypeAttributes->findByObjectType($objectType) as $junction) {
            $a = $junction->getAttribute();
            if ($a->getType()->usesOptions()) {
                $optionsAttrs[$a->getId()->toRfc4122()] = $a;
            }
        }
        $optionsByAttributeId = $this->loadOptionsByAttributeId(array_values($optionsAttrs));

        $seenAttributeIds = [];
        $effective = [];
        foreach ($groups as $position => $group) {
            $junctions = $byGroup[$group->getId()->toRfc4122()] ?? [];
            $attributes = [];
            foreach ($junctions as $j) {
                $attribute = $j->getAttribute();
                $seenAttributeIds[$attribute->getId()->toRfc4122()] = true;
                $attributes[] = $this->serializeAttribute(
                    $attribute,
                    $j->getPosition(),
                    $j->isRequiredInGroup(),
                    $j->getVisibleWhen(),
                    $optionsByAttributeId,
                );
            }
            $effective[] = [
                'id' => $group->getId()->toRfc4122(),
                'code' => $group->getCode(),
                'label' => $group->getLabel(),
                'icon' => $group->getIcon(),
                'color' => $group->getColor(),
                'is_system_group' => $group->isSystemGroup(),
                'position' => $position,
                'display_mode' => $displayModes[$group->getId()->toRfc4122()] ?? 'tab',
                'attributes' => $attributes,
            ];
        }

        $junctions = $this->objectTypeAttributes->findByObjectType($objectType);
        usort(
            $junctions,
            static fn ($a, $b): int => $a->getSortOrder() <=> $b->getSortOrder(),
        );
        $defaultAttributes = [];
        foreach ($junctions as $junction) {
            $attribute = $junction->getAttribute();
            $key = $attribute->getId()->toRfc4122();
            if (isset($seenAttributeIds[$key])) {
                continue;
            }
            $seenAttributeIds[$key] = true;
            $defaultAttributes[] = $this->serializeAttribute(
                $attribute,
                $junction->getSortOrder(),
                $junction->isRequiredForCompleteness(),
                null,
                $optionsByAttributeId,
            );
        }

        if ([] !== $defaultAttributes) {
            $effective[] = [
                'id' => ProductReadEndpointsController::SYNTHETIC_DEFAULT_GROUP_ID,
                'code' => ProductReadEndpointsController::SYNTHETIC_DEFAULT_GROUP_CODE,
                'label' => ['pl' => 'Atrybuty', 'en' => 'Attributes'],
                'icon' => null,
                'color' => null,
                'is_system_group' => false,
                'is_synthetic' => true,
                'position' => \count($effective),
                'display_mode' => 'stacked',
                'attributes' => $defaultAttributes,
            ];
        }

        return new JsonResponse([
            'object_type_id' => $objectType->getId()->toRfc4122(),
            'groups' => $effective,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(Request $request): array
    {
        $body = $request->getContent();
        if ('' === $body) {
            return [];
        }
        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new BadRequestHttpException('Request body is not valid JSON.', $e);
        }
        if (!\is_array($decoded)) {
            throw new BadRequestHttpException('Request body must be a JSON object.');
        }
        $normalized = [];
        foreach ($decoded as $key => $value) {
            if (\is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param list<Attribute> $attributes
     *
     * @return array<string, list<array{code: string, label: array<string, string>, color: ?string, is_default: bool, is_deprecated: bool}>>
     */
    private function loadOptionsByAttributeId(array $attributes): array
    {
        $byId = [];
        foreach ($this->attributeOptions->findByAttributes($attributes) as $option) {
            $byId[$option->getAttribute()->getId()->toRfc4122()][] = [
                'code' => $option->getCode(),
                'label' => $option->getLabel(),
                'color' => $option->getColor(),
                'is_default' => $option->isDefault(),
                'is_deprecated' => $option->isDeprecated(),
            ];
        }

        return $byId;
    }

    /**
     * @param array<string, list<array{code: string, label: array<string, string>, color: ?string, is_default: bool, is_deprecated: bool}>> $optionsByAttributeId
     *
     * @return array<string, mixed>
     */
    private function serializeAttribute(
        Attribute $attribute,
        int $position,
        bool $isRequiredInGroup,
        mixed $visibleWhen,
        array $optionsByAttributeId,
    ): array {
        $payload = [
            'id' => $attribute->getId()->toRfc4122(),
            'code' => $attribute->getCode(),
            'type' => $attribute->getType()->value,
            'label' => $attribute->getLabel(),
            'is_system' => $attribute->isSystem(),
            'position' => $position,
            'is_required_in_group' => $isRequiredInGroup,
            'visible_when' => $visibleWhen,
        ];

        if ($attribute->getType()->usesOptions()) {
            $payload['options'] = $optionsByAttributeId[$attribute->getId()->toRfc4122()] ?? [];
        }

        // MODR-05 (#927) — mirror the relation metadata exposed by the
        // persisted endpoint so the create form's preview renders the
        // same link icon + tooltip.
        if (AttributeType::Relation === $attribute->getType()) {
            $payload['relation_target_object_type_ids'] = $attribute->getRelationTargetObjectTypeIds();
            $payload['relation_cardinality'] = $attribute->getRelationCardinality()?->value;
            $payload['relation_preview_fields'] = $attribute->getRelationPreviewFields();
        }

        return $payload;
    }
}
