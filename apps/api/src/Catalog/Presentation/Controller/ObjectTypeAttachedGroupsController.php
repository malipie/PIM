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
 * VIEW-01 (#372) — `GET /api/object_types/{id}/attached_groups` returns
 * the AttributeGroups attached to the given ObjectType in the shape the
 * modeling Detail view's two cards (Built-in / Custom attribute groups)
 * need:
 *
 *   - sorted by position ASC, then by code,
 *   - includes `system` flag so the FE can split the two sections,
 *   - includes `attrsCount` + `attrsPreview` (max 8) so the GroupCard
 *     chip strip renders without a follow-up fetch,
 *   - includes `color` + `icon` straight from `attribute_groups`.
 */
final class ObjectTypeAttachedGroupsController
{
    private const string UUID_REGEX = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
    private const int ATTRS_PREVIEW_LIMIT = 8;

    public function __construct(
        private readonly ObjectTypeRepositoryInterface $objectTypes,
        private readonly Connection $connection,
    ) {
    }

    #[Route(
        '/api/object_types/{id}/attached_groups',
        name: 'pim_object_types_attached_groups',
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
                    SELECT g.id, g.code, g.label, g.icon, g.color,
                           g.is_system_group AS system,
                           j.position,
                           j.display_mode
                    FROM object_type_attribute_groups j
                    JOIN attribute_groups g ON g.id = j.attribute_group_id
                    WHERE j.object_type_id = :ot
                    ORDER BY j.position ASC, g.code ASC
                SQL,
            ['ot' => $id],
        );

        $groupIds = array_map(
            static fn (array $row): string => self::stringify($row['id'] ?? ''),
            $rows,
        );
        $attrsByGroup = $this->loadAttrsForGroups($groupIds);

        $entries = [];
        foreach ($rows as $row) {
            $groupId = self::stringify($row['id'] ?? '');
            $attrs = $attrsByGroup[$groupId] ?? [];
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

            $displayMode = self::stringify($row['display_mode'] ?? 'tab');
            $entries[] = [
                'id' => $groupId,
                'code' => self::stringify($row['code'] ?? ''),
                'label' => $label,
                'icon' => $row['icon'] ?? null,
                'color' => $row['color'] ?? null,
                'system' => (bool) $row['system'],
                'attrsCount' => \count($attrs),
                'attrsPreview' => \array_slice($attrs, 0, self::ATTRS_PREVIEW_LIMIT),
                'displayMode' => '' !== $displayMode ? $displayMode : 'tab',
                'position' => \is_numeric($row['position'] ?? null) ? (int) $row['position'] : 0,
            ];
        }

        return new JsonResponse($entries);
    }

    /**
     * @param list<string> $groupIds
     *
     * @return array<string, list<string>> map of group id → attribute codes
     */
    private function loadAttrsForGroups(array $groupIds): array
    {
        if ([] === $groupIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, \count($groupIds), '?'));
        $rows = $this->connection->fetchAllAssociative(
            'SELECT j.attribute_group_id AS gid, a.code'
            .' FROM attribute_group_attributes j'
            .' JOIN attributes a ON a.id = j.attribute_id'
            .' WHERE j.attribute_group_id IN ('.$placeholders.')'
            .' ORDER BY j.position ASC, a.code ASC',
            $groupIds,
        );

        $out = [];
        foreach ($rows as $row) {
            $gid = self::stringify($row['gid'] ?? '');
            $code = self::stringify($row['code'] ?? '');
            $out[$gid] ??= [];
            $out[$gid][] = $code;
        }

        return $out;
    }

    private static function stringify(mixed $value): string
    {
        return \is_scalar($value) || $value instanceof Stringable ? (string) $value : '';
    }
}
