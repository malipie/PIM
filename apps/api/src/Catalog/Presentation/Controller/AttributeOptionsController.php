<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Controller;

use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeOption;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Shared\Application\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use const JSON_THROW_ON_ERROR;

/**
 * VIEW-02 (#374) — read + manage AttributeOption rows backing the
 * Allowed Values editor (`/modeling/attributes/{code}/values`).
 *
 * Exposed as Symfony routes (not AP4 ApiResource) because the editor
 * works against the parent Attribute by `code` (FE-friendly URL) and
 * carries non-trivial business rules (one default per attribute, hex
 * format guard, deprecation toggle without deletion).
 *
 *   GET    /api/attributes/{code}/options              list options (sorted by position)
 *   POST   /api/attributes/{code}/options              create option
 *   PATCH  /api/attributes/{code}/options/{optionCode} partial update
 *   DELETE /api/attributes/{code}/options/{optionCode} remove option
 *
 * `findByAttribute()` already sorts by (position, code) per repo
 * contract, so the FE list shows options in the editor-defined order.
 */
final class AttributeOptionsController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AttributeRepositoryInterface $attributes,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route(
        '/api/attributes/{code}/options',
        name: 'pim_attribute_options_list',
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'attribute', action: 'read', anyOf: [
        'attribute.read',
        'modeling.view',
        'products.view',
        'categories.view',
        'multimedia.view',
        'products.add',
        'categories.add_edit',
        'multimedia.add_edit_own',
        'multimedia.add_edit_any',
        'object.add',
    ])]
    public function list(string $code): JsonResponse
    {
        $attribute = $this->loadAttributeByCode($code);

        $options = $this->em->getRepository(AttributeOption::class)
            ->findBy(['attribute' => $attribute], ['position' => 'ASC', 'code' => 'ASC']);

        return new JsonResponse(['member' => array_map([self::class, 'serialize'], $options)]);
    }

    /**
     * VIEW-02 (#374) — per-option instance count for the Allowed Values
     * editor's `<AttributeValueAuditCard>`. Counts every `object_value`
     * row whose `value` JSONB references the option code:
     *   - select   → `value->>'value' = '{optionCode}'`
     *   - multiselect → `value->'option_codes' ? '{optionCode}'`
     *
     * The single SQL covers both shapes via OR — JSONB `?` operator on
     * a non-array value is `false` so the select branch only matches
     * `value->>'value'`. Tenant scoping piggybacks on object_values
     * (already tenant-stamped via Doctrine listener).
     */
    #[Route(
        '/api/attributes/{code}/options/{optionCode}/usage',
        name: 'pim_attribute_options_usage',
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'attribute', action: 'read', anyOf: [
        'attribute.read',
        'modeling.view',
        'products.view',
        'categories.view',
        'multimedia.view',
        'products.add',
        'categories.add_edit',
        'multimedia.add_edit_own',
        'multimedia.add_edit_any',
        'object.add',
    ])]
    public function usage(string $code, string $optionCode): JsonResponse
    {
        $attribute = $this->loadAttributeByCode($code);
        $option = $this->loadOption($attribute, $optionCode);

        // Postgres JSONB containment (`@>`) lets us cover both shapes
        // without using the `?` JSONB operator (DBAL parses `?` as a
        // bind placeholder).
        $selectShape = json_encode(['value' => $option->getCode()], JSON_THROW_ON_ERROR);
        $multiShape = json_encode(['option_codes' => [$option->getCode()]], JSON_THROW_ON_ERROR);
        $row = $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM object_values'
            .' WHERE attribute_id = ?'
            .' AND (value @> ?::jsonb OR value @> ?::jsonb)',
            [$attribute->getId()->toRfc4122(), $selectShape, $multiShape],
        );
        $instances = \is_scalar($row) ? (int) $row : 0;

        return new JsonResponse(['instances' => $instances]);
    }

    #[Route(
        '/api/attributes/{code}/options',
        name: 'pim_attribute_options_create',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'modeling.attributes', action: 'add_edit')]
    public function create(string $code, Request $request): JsonResponse
    {
        $attribute = $this->loadAttributeByCode($code);
        $body = $this->decodeBody($request);

        $optionCode = $body['code'] ?? null;
        if (!\is_string($optionCode) || '' === $optionCode) {
            throw new BadRequestHttpException('code is required.');
        }
        $label = $body['label'] ?? null;
        if (!\is_array($label) || [] === $label) {
            throw new BadRequestHttpException('label must be a non-empty JSONB object.');
        }
        $position = $body['position'] ?? null;
        $color = $body['color'] ?? null;
        $isDefault = (bool) ($body['default'] ?? $body['isDefault'] ?? false);
        $isDeprecated = (bool) ($body['deprecated'] ?? $body['isDeprecated'] ?? false);

        // Auto-assign position if not provided.
        if (!\is_int($position)) {
            $maxRow = $this->em->getConnection()->fetchOne(
                'SELECT COALESCE(MAX(position), -1) FROM attribute_options WHERE attribute_id = ?',
                [$attribute->getId()->toRfc4122()],
            );
            $position = (\is_scalar($maxRow) ? (int) $maxRow : -1) + 1;
        }

        $existing = $this->em->getRepository(AttributeOption::class)
            ->findOneBy(['attribute' => $attribute, 'code' => $optionCode]);
        if ($existing instanceof AttributeOption) {
            throw new UnprocessableEntityHttpException(\sprintf(
                'AttributeOption "%s" already exists for attribute "%s".',
                $optionCode,
                $code,
            ));
        }

        /** @var array<string, string> $stringLabel */
        $stringLabel = [];
        foreach ($label as $k => $v) {
            $stringLabel[(string) $k] = \is_scalar($v) ? (string) $v : '';
        }

        $option = new AttributeOption(
            attribute: $attribute,
            code: $optionCode,
            label: $stringLabel,
            position: $position,
            color: \is_string($color) ? $color : null,
            isDefault: $isDefault,
            isDeprecated: $isDeprecated,
        );
        if ($isDefault) {
            $this->clearOtherDefaults($attribute, $option);
        }

        $this->em->persist($option);
        $this->em->flush();

        return new JsonResponse(self::serialize($option), 201);
    }

    #[Route(
        '/api/attributes/{code}/options/{optionCode}',
        name: 'pim_attribute_options_patch',
        methods: ['PATCH'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'modeling.attributes', action: 'add_edit')]
    public function patch(string $code, string $optionCode, Request $request): JsonResponse
    {
        $attribute = $this->loadAttributeByCode($code);
        $option = $this->loadOption($attribute, $optionCode);
        $body = $this->decodeBody($request);

        if (\array_key_exists('label', $body)) {
            $label = $body['label'];
            if (!\is_array($label)) {
                throw new BadRequestHttpException('label must be a JSONB object.');
            }
            /** @var array<string, string> $stringLabel */
            $stringLabel = [];
            foreach ($label as $k => $v) {
                $stringLabel[(string) $k] = \is_scalar($v) ? (string) $v : '';
            }
            $option->rename($stringLabel);
        }
        if (\array_key_exists('position', $body)) {
            $position = $body['position'];
            if (!\is_int($position)) {
                throw new BadRequestHttpException('position must be an integer.');
            }
            $option->reorder($position);
        }
        if (\array_key_exists('color', $body)) {
            $color = $body['color'];
            $option->setColor(\is_string($color) ? $color : null);
        }
        if (\array_key_exists('default', $body) || \array_key_exists('isDefault', $body)) {
            $isDefault = (bool) ($body['default'] ?? $body['isDefault'] ?? false);
            if ($isDefault) {
                $this->clearOtherDefaults($attribute, $option);
            }
            $option->setDefault($isDefault);
        }
        if (\array_key_exists('deprecated', $body) || \array_key_exists('isDeprecated', $body)) {
            $option->setDeprecated((bool) ($body['deprecated'] ?? $body['isDeprecated'] ?? false));
        }

        $this->em->flush();

        return new JsonResponse(self::serialize($option));
    }

    #[Route(
        '/api/attributes/{code}/options/{optionCode}',
        name: 'pim_attribute_options_delete',
        methods: ['DELETE'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'attribute', action: 'delete', anyOf: [
        'attribute.delete',
        'modeling.delete_custom',
    ])]
    public function delete(string $code, string $optionCode): JsonResponse
    {
        $attribute = $this->loadAttributeByCode($code);
        $option = $this->loadOption($attribute, $optionCode);

        try {
            $this->em->remove($option);
            $this->em->flush();
        } catch (HttpException $e) {
            throw $e;
        }

        return new JsonResponse(null, 204);
    }

    private function loadAttributeByCode(string $code): Attribute
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new LogicException('TenantContext must be populated for option calls.');
        }
        $attribute = $this->attributes->findByCode($code, $tenant);
        if (null === $attribute) {
            throw new NotFoundHttpException(\sprintf('Attribute "%s" was not found.', $code));
        }

        return $attribute;
    }

    private function loadOption(Attribute $attribute, string $optionCode): AttributeOption
    {
        $option = $this->em->getRepository(AttributeOption::class)
            ->findOneBy(['attribute' => $attribute, 'code' => $optionCode]);
        if (!$option instanceof AttributeOption) {
            throw new NotFoundHttpException(\sprintf(
                'AttributeOption "%s.%s" was not found.',
                $attribute->getCode(),
                $optionCode,
            ));
        }

        return $option;
    }

    private function clearOtherDefaults(Attribute $attribute, AttributeOption $newDefault): void
    {
        $others = $this->em->getRepository(AttributeOption::class)
            ->findBy(['attribute' => $attribute]);
        foreach ($others as $other) {
            if ($other->getId()->equals($newDefault->getId()) || !$other->isDefault()) {
                continue;
            }
            $other->setDefault(false);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(Request $request): array
    {
        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            throw new BadRequestHttpException('Request body must be a JSON object.');
        }

        $normalized = [];
        foreach ($body as $k => $v) {
            $normalized[(string) $k] = $v;
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private static function serialize(AttributeOption $option): array
    {
        return [
            'id' => $option->getId()->toRfc4122(),
            'code' => $option->getCode(),
            'label' => $option->getLabel(),
            'position' => $option->getPosition(),
            'color' => $option->getColor(),
            'default' => $option->isDefault(),
            'deprecated' => $option->isDeprecated(),
        ];
    }
}
