<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Controller;

use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use Doctrine\DBAL\Connection;
use Stringable;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * VIEW-01b (#413) — `GET /api/object_types/{id}/attached_attributes` returns
 * attributes attached directly (not via an AttributeGroup) to the given
 * ObjectType. Powers the "Custom attribute" card on the modeling Detail
 * view, which lists single attributes the operator has bolted onto a type
 * outside any group.
 *
 * Sort order: `j.sort_order ASC, a.code ASC` — same convention as
 * `attribute_group_attributes` so operators get consistent ordering when
 * attributes flow between group/direct contexts.
 */
final class ObjectTypeAttachedAttributesController
{
    private const string UUID_REGEX = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        private readonly ObjectTypeRepositoryInterface $objectTypes,
        private readonly Connection $connection,
    ) {
    }

    #[Route(
        '/api/object_types/{id}/attached_attributes',
        name: 'pim_object_types_attached_attributes',
        requirements: ['id' => self::UUID_REGEX],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'object_type', action: 'read', anyOf: [
        'object_type.read',
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
    public function __invoke(string $id): JsonResponse
    {
        $objectType = $this->objectTypes->findById(Uuid::fromString($id));
        if (null === $objectType) {
            throw new NotFoundHttpException(\sprintf('ObjectType "%s" was not found.', $id));
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                    SELECT a.id, a.code, a.label, a.type,
                           j.required_for_completeness AS required,
                           j.sort_order,
                           a.is_system,
                           g.id AS group_id, g.code AS group_code
                    FROM object_type_attributes j
                    JOIN attributes a ON a.id = j.attribute_id
                    LEFT JOIN attribute_groups g ON g.id = a.group_id
                    WHERE j.object_type_id = :ot
                    ORDER BY j.sort_order ASC, a.code ASC
                SQL,
            ['ot' => $id],
        );

        $entries = [];
        foreach ($rows as $row) {
            $labelRaw = $row['label'] ?? null;
            if (\is_string($labelRaw)) {
                $decoded = json_decode($labelRaw, true);
                $labelRaw = \is_array($decoded) ? $decoded : [];
            }
            $label = [];
            if (\is_array($labelRaw)) {
                foreach ($labelRaw as $k => $v) {
                    if (\is_string($k) && \is_string($v)) {
                        $label[$k] = $v;
                    }
                }
            }

            $groupId = self::stringify($row['group_id'] ?? '');
            $groupCode = self::stringify($row['group_code'] ?? '');
            $entries[] = [
                'id' => self::stringify($row['id'] ?? ''),
                'code' => self::stringify($row['code'] ?? ''),
                'label' => $label,
                'type' => self::stringify($row['type'] ?? ''),
                'required' => (bool) ($row['required'] ?? false),
                'sortOrder' => self::intify($row['sort_order'] ?? 0),
                'isSystem' => (bool) ($row['is_system'] ?? false),
                'group' => '' === $groupId ? null : [
                    'id' => $groupId,
                    'code' => $groupCode,
                ],
            ];
        }

        return new JsonResponse($entries);
    }

    private static function stringify(mixed $value): string
    {
        return \is_scalar($value) || $value instanceof Stringable ? (string) $value : '';
    }

    private static function intify(mixed $value): int
    {
        return \is_scalar($value) ? (int) $value : 0;
    }
}
