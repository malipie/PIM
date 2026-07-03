<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Controller;

use App\Catalog\Application\ObjectTypeService;
use App\Catalog\Domain\Exception\BuiltInObjectTypeException;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * VIEW-01 (#372) — `PATCH /api/object_types/{id}` for inline edits in the
 * modeling Detail view. Built-in rows accept only `label`, `icon`, `color`
 * mutations (the service rejects anything else with `fieldLocked`); custom
 * rows accept the full payload (settings, parent types, completeness rules).
 *
 * Why a custom controller instead of API Platform Patch operation? The
 * field-locking rules are highly conditional on the `is_built_in` flag and
 * carry domain exceptions; mapping that through API Platform input DTOs +
 * state processor adds three indirection layers without earning anything.
 * The existing `CreateCustomObjectTypeController` already established the
 * pattern (priority-200 route alongside the read-only ApiResource).
 */
final class UpdateObjectTypeController
{
    private const string UUID_REGEX = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        private readonly ObjectTypeService $service,
        private readonly ObjectTypeRepositoryInterface $objectTypes,
    ) {
    }

    #[Route(
        '/api/object_types/{id}',
        name: 'pim_object_types_update',
        requirements: ['id' => self::UUID_REGEX],
        methods: ['PATCH'],
        priority: 200,
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'modeling.object_types', action: 'add')]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $objectType = $this->objectTypes->findById(Uuid::fromString($id));
        if (null === $objectType) {
            throw new NotFoundHttpException(\sprintf('ObjectType "%s" was not found.', $id));
        }

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        try {
            $this->service->update(
                objectType: $objectType,
                label: $this->labelOrNull($body['label'] ?? null),
                icon: $this->stringOrNullField($body, 'icon'),
                color: $this->stringOrNullField($body, 'color'),
                hierarchical: $this->boolOrNull($body['hierarchical'] ?? null),
                hasVariants: $this->boolOrNull($body['hasVariants'] ?? null),
                abstract: $this->boolOrNull($body['abstract'] ?? null),
                allowedParentTypeIds: $this->idListOrNull($body['allowedParentTypeIds'] ?? null),
                completenessRules: $this->mapOrNull($body['completenessRules'] ?? null),
                validationRules: $this->ruleListOrNull($body['validationRules'] ?? null),
                exposeToMainMenu: $this->boolOrNull($body['exposeToMainMenu'] ?? null),
                isCategorizable: $this->boolOrNull($body['isCategorizable'] ?? null),
                hasMultimedia: $this->boolOrNull($body['hasMultimedia'] ?? null),
            );
        } catch (BuiltInObjectTypeException $e) {
            throw new HttpException(Response::HTTP_FORBIDDEN, $e->getMessage(), $e);
        } catch (Throwable $e) {
            throw new HttpException(Response::HTTP_UNPROCESSABLE_ENTITY, $e->getMessage(), $e);
        }

        return new JsonResponse([
            'id' => $objectType->getId()->toRfc4122(),
            'code' => $objectType->getCode(),
            'kind' => $objectType->getKind()->value,
            'label' => $objectType->getLabel(),
            'icon' => $objectType->getIcon(),
            'color' => $objectType->getColor(),
            'builtIn' => $objectType->isBuiltIn(),
            'hierarchical' => $objectType->isHierarchical(),
            'hasVariants' => $objectType->hasVariants(),
            'abstract' => $objectType->isAbstract(),
            'allowedParentTypeIds' => $objectType->getAllowedParentTypeIds(),
            'completenessRules' => $objectType->getCompletenessRules(),
            'validationRules' => $objectType->getValidationRules(),
            'exposeToMainMenu' => $objectType->isExposedToMainMenu(),
            'isCategorizable' => $objectType->isCategorizable(),
            'hasMultimedia' => $objectType->hasMultimedia(),
            'schemaVersion' => $objectType->getSchemaVersion(),
        ]);
    }

    /**
     * @return array<string, string>|null
     */
    private function labelOrNull(mixed $raw): ?array
    {
        if (null === $raw) {
            return null;
        }
        if (!\is_array($raw) || [] === $raw) {
            throw new BadRequestHttpException('label must be a non-empty per-locale map.');
        }
        $clean = [];
        foreach ($raw as $locale => $value) {
            if (\is_string($locale) && \is_string($value) && '' !== trim($value)) {
                $clean[$locale] = trim($value);
            }
        }
        if ([] === $clean) {
            throw new BadRequestHttpException('label must contain at least one non-empty entry.');
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function stringOrNullField(array $body, string $field): ?string
    {
        if (!\array_key_exists($field, $body)) {
            return null;
        }
        $raw = $body[$field];
        if (null === $raw || '' === $raw) {
            return null;
        }
        if (!\is_string($raw)) {
            throw new BadRequestHttpException(\sprintf('%s must be a string.', $field));
        }

        return trim($raw);
    }

    private function boolOrNull(mixed $raw): ?bool
    {
        if (null === $raw) {
            return null;
        }
        if (\is_bool($raw)) {
            return $raw;
        }
        throw new BadRequestHttpException('Boolean fields must be true or false.');
    }

    /**
     * @return list<string>|null
     */
    private function idListOrNull(mixed $raw): ?array
    {
        if (null === $raw) {
            return null;
        }
        if (!\is_array($raw)) {
            throw new BadRequestHttpException('allowedParentTypeIds must be a list.');
        }
        $clean = [];
        foreach ($raw as $value) {
            if (!\is_string($value) || !Uuid::isValid($value)) {
                throw new BadRequestHttpException('allowedParentTypeIds entries must be UUID strings.');
            }
            $clean[] = $value;
        }

        return array_values(array_unique($clean));
    }

    /**
     * DP-07 (#2037) — validationRules must be a JSON list of objects; the
     * deep shape (rule types, operators, referenced codes) is validated in
     * ObjectTypeService via CrossFieldRules::fromArray.
     *
     * @return list<array<string, mixed>>|null
     */
    private function ruleListOrNull(mixed $raw): ?array
    {
        if (null === $raw) {
            return null;
        }
        if (!\is_array($raw) || !array_is_list($raw)) {
            throw new BadRequestHttpException('validationRules must be a JSON list.');
        }
        foreach ($raw as $entry) {
            if (!\is_array($entry)) {
                throw new BadRequestHttpException('validationRules entries must be objects.');
            }
        }

        /* @var list<array<string, mixed>> $raw */
        return $raw;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapOrNull(mixed $raw): ?array
    {
        if (null === $raw) {
            return null;
        }
        if (!\is_array($raw)) {
            throw new BadRequestHttpException('completenessRules must be an object.');
        }
        // Trust upstream — keys/values structure validated by the listener
        // that interprets the rules (epic 0.6 hook).
        $clean = [];
        foreach ($raw as $key => $value) {
            if (!\is_string($key)) {
                throw new BadRequestHttpException('completenessRules keys must be strings.');
            }
            $clean[$key] = $value;
        }

        return $clean;
    }
}
