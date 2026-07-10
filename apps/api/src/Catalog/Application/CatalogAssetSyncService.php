<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Contracts\Service\CatalogAssetSync;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Application\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * Implementation of {@see CatalogAssetSync} for the Asset upload
 * pipeline (#438).
 *
 * Each freshly uploaded `Asset` row gets a matching `CatalogObject`
 * of `kind=asset` so the read surface (`/api/assets` GET) can list it.
 * Storage-side fields (`mime`, `filename`, `previewUrl`,
 * `thumbnailsStatus`, `tags`) are written straight into
 * `attributes_indexed` — they are denormalised cache for the grid and
 * have no matching `Attribute` rows in EAV (mirrors the demo
 * seeder's pattern, see `DemoCatalogSeeder::seedAssets`).
 *
 * The service is idempotent on `assetId`: re-running with the same id
 * (e.g. the thumbnail worker calling back after `ready`) refreshes
 * the indexed payload on the existing row instead of creating a
 * duplicate `CatalogObject`.
 */
final readonly class CatalogAssetSyncService implements CatalogAssetSync
{
    public function __construct(
        private EntityManagerInterface $em,
        private ObjectTypeRepositoryInterface $objectTypes,
        private CatalogObjectRepositoryInterface $catalogObjects,
        private TenantContext $tenantContext,
    ) {
    }

    public function syncFromUploadedAsset(
        Uuid $assetId,
        string $code,
        array $indexedAttributes,
    ): Uuid {
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new RuntimeException('CatalogAssetSyncService requires an active TenantContext.');
        }

        $assetType = $this->objectTypes->findBuiltInByKind(ObjectKind::Asset, $tenant);
        if (null === $assetType) {
            throw new RuntimeException('Built-in Asset ObjectType is missing for current tenant.');
        }

        $existing = $this->catalogObjects->findByCode($code, ObjectKind::Asset, $tenant);
        if (null !== $existing) {
            $existing->updateAttributeIndex($indexedAttributes);
            $this->em->flush();

            return $existing->getId();
        }

        $catalogObject = new CatalogObject($assetType, $code);
        $catalogObject->forceStatus(CatalogObject::STATUS_PUBLISHED);
        $catalogObject->updateAttributeIndex($indexedAttributes);
        $catalogObject->recordCompleteness(['global' => 100]);

        $this->em->persist($catalogObject);
        $this->em->flush();

        return $catalogObject->getId();
    }

    public function removeForAsset(string $code): void
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            return;
        }

        $existing = $this->catalogObjects->findByCode($code, ObjectKind::Asset, $tenant);
        if (null === $existing) {
            return;
        }

        $this->em->remove($existing);
        $this->em->flush();
    }
}
