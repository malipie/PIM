<?php

declare(strict_types=1);

namespace App\Asset\Presentation\Controller;

use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Shared\Application\TenantContext;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /api/assets/folders` (#440).
 *
 * Returns the list of distinct `folder_code` values seen on the
 * tenant's assets together with a human-readable display name and the
 * count of files inside. The grid renders these as folder tiles when
 * the user is at the library root.
 *
 * Display name resolution:
 *   - `product-<UUID>` → the linked product's `code` from the
 *     `objects` table (kind=product). Missing product fallback is the
 *     raw `<UUID>` so the operator at least sees something.
 *   - everything else → the folder code verbatim.
 */
final readonly class AssetFoldersController
{
    public function __construct(
        private Connection $connection,
        private TenantContext $tenantContext,
    ) {
    }

    #[Route(path: '/api/asset-folders', name: 'pim_asset_folders', methods: ['GET'], format: 'json')]
    #[RequiresPermission(module: 'asset', action: 'read', anyOf: [
        'asset.read',
        'multimedia.view',
    ])]
    public function __invoke(): JsonResponse
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new RuntimeException('Folders endpoint requires an active TenantContext.');
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT folder_code, COUNT(*) AS asset_count
                FROM assets
                WHERE tenant_id = :tenantId AND folder_code IS NOT NULL
                GROUP BY folder_code
                ORDER BY folder_code
                SQL,
            ['tenantId' => $tenant->getId()->toRfc4122()],
        );

        if ([] === $rows) {
            return new JsonResponse(['member' => [], 'totalItems' => 0]);
        }

        $productIds = [];
        foreach ($rows as $row) {
            if (!\is_string($row['folder_code'] ?? null)) {
                continue;
            }
            $productId = $this->extractProductId($row['folder_code']);
            if (null !== $productId) {
                $productIds[] = $productId;
            }
        }

        $productNames = [] !== $productIds ? $this->fetchProductNames($productIds, $tenant->getId()->toRfc4122()) : [];

        $member = [];
        foreach ($rows as $row) {
            if (!\is_string($row['folder_code'] ?? null)) {
                continue;
            }
            $code = $row['folder_code'];
            $member[] = [
                'code' => $code,
                'displayName' => $this->resolveDisplayName($code, $productNames),
                'assetCount' => is_numeric($row['asset_count'] ?? null) ? (int) $row['asset_count'] : 0,
            ];
        }

        return new JsonResponse(['member' => $member, 'totalItems' => \count($member)]);
    }

    private function extractProductId(string $folderCode): ?string
    {
        if (!str_starts_with($folderCode, 'product-')) {
            return null;
        }

        return substr($folderCode, \strlen('product-'));
    }

    /**
     * @param array<int, string> $productIds
     *
     * @return array<string, string> id → code
     */
    private function fetchProductNames(array $productIds, string $tenantId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT id::text AS id, code FROM objects
                WHERE tenant_id = :tenantId AND kind = 'product' AND id IN (:ids)
                SQL,
            ['tenantId' => $tenantId, 'ids' => $productIds],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );

        $map = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $code = $row['code'] ?? null;
            if (\is_string($id) && \is_string($code)) {
                $map[$id] = $code;
            }
        }

        return $map;
    }

    /**
     * @param array<string, string> $productNames
     */
    private function resolveDisplayName(string $folderCode, array $productNames): string
    {
        $productId = $this->extractProductId($folderCode);
        if (null !== $productId && isset($productNames[$productId])) {
            return 'Produkt: '.$productNames[$productId];
        }

        return $folderCode;
    }
}
