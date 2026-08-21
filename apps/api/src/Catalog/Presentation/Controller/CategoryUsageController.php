<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Controller;

use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * VIEW-04 (#408) — `GET /api/categories/{id}/usage`
 *
 * Aggregate counts powering the category Detail panel header (instances)
 * + the modeling Show page Where-used section + the delete confirmation
 * preflight (so the FE can render "X obiektów / Y podgałęzi" before
 * the operator even fires DELETE).
 *
 * Response shape:
 *   {
 *     "categoryId": "...",
 *     "instanceCount": 4,         // objects assigned through object_categories
 *     "descendantCount": 6,       // categories with path <@ this.path (excl. self)
 *     "declaredFor": [
 *       { "targetObjectTypeKind": "service", "groupCount": 2 },
 *       { "targetObjectTypeKind": "product", "groupCount": 0 }
 *     ]
 *   }
 *
 * No N+1 — three single-row aggregate queries; the `declaredFor` list
 * is a single GROUP BY.
 */
final class CategoryUsageController
{
    private const string UUID_REGEX = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        private readonly CatalogObjectRepositoryInterface $catalogObjects,
        private readonly Connection $connection,
    ) {
    }

    #[Route(
        '/api/categories/{id}/usage',
        name: 'pim_categories_usage',
        requirements: ['id' => self::UUID_REGEX],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'categories', action: 'view')]
    public function __invoke(string $id): JsonResponse
    {
        $category = $this->catalogObjects->findById(Uuid::fromString($id));
        if (null === $category) {
            throw new NotFoundHttpException(\sprintf('Category "%s" was not found.', $id));
        }
        if (ObjectKind::Category !== $category->getKind()) {
            throw new UnprocessableEntityHttpException(\sprintf('Object "%s" is not a category.', $id));
        }

        $thisId = $category->getId()->toRfc4122();
        $thisPath = $category->getPath();

        $instanceCount = self::toInt($this->connection->fetchOne(
            'SELECT COUNT(DISTINCT object_id) FROM object_categories WHERE category_id = CAST(:id AS uuid)',
            ['id' => $thisId],
        ));

        $descendantCount = 0;
        if (null !== $thisPath && '' !== $thisPath) {
            $descendantCount = self::toInt($this->connection->fetchOne(
                "SELECT COUNT(*) FROM objects WHERE kind = 'category' AND path <@ CAST(:path AS ltree) AND id <> CAST(:id AS uuid)",
                ['path' => $thisPath, 'id' => $thisId],
            ));
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ot.kind AS kind, COUNT(*) AS group_count'
            .' FROM category_attribute_groups cag'
            .' INNER JOIN object_types ot ON ot.id = cag.target_object_type_id'
            .' WHERE cag.category_object_id = CAST(:id AS uuid)'
            .' GROUP BY ot.kind',
            ['id' => $thisId],
        );

        $declaredFor = [];
        foreach ($rows as $row) {
            $kind = $row['kind'] ?? null;
            $count = $row['group_count'] ?? null;
            if (!\is_string($kind)) {
                continue;
            }
            $declaredFor[] = [
                'targetObjectTypeKind' => $kind,
                'groupCount' => self::toInt($count),
            ];
        }

        return new JsonResponse([
            'categoryId' => $thisId,
            'instanceCount' => $instanceCount,
            'descendantCount' => $descendantCount,
            'declaredFor' => $declaredFor,
        ]);
    }

    /**
     * Coerce a DBAL scalar (string|int|null per driver) into int. Aggregate
     * results from `COUNT(*)` arrive as strings under the pdo_pgsql driver
     * but as ints under others; PHPStan rejects a direct (int) cast on
     * `mixed`, so this helper centralises the assertion.
     */
    private static function toInt(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && '' !== $value) {
            return (int) $value;
        }

        return 0;
    }
}
