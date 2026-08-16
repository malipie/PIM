<?php

declare(strict_types=1);

namespace App\Asset\Presentation\Controller;

use App\Asset\Application\AssetMetadataUpdater;
use App\Asset\Domain\Entity\Asset;
use App\Asset\Domain\Repository\AssetRepositoryInterface;
use App\Catalog\Contracts\Service\CatalogAssetSync;
use App\Identity\Contracts\Attribute\RequiresPermission;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * `PATCH /api/assets/{id}` (#438) — edits Asset-side metadata that
 * lives on the storage row: `code` (re-slug, unique per tenant) and
 * `tags` (chip multi-select).
 *
 * Localised `alt` text is owned by the linked CatalogObject's
 * `attributes_indexed` and edited via the catalog write path — Deptrac
 * (ADR-0013) blocks Asset_Internals from reaching into
 * Catalog_Internals to dispatch the update directly. A follow-up adds
 * a `Catalog_Contracts` writer for cross-context use; until then the
 * frontend renders alt as read-only on the show view.
 */
final readonly class PatchAssetController
{
    public function __construct(
        private AssetRepositoryInterface $assets,
        private AssetMetadataUpdater $metadataUpdater,
        private AuthorizationCheckerInterface $authorisation,
        private CatalogAssetSync $catalogAssetSync,
    ) {
    }

    #[Route(path: '/api/assets/{id}', name: 'pim_assets_patch', methods: ['PATCH'], format: 'json')]
    // #2881 — both multimedia write codes are accepted because the
    // `assets` table carries no uploader column, so `_own` and `_any`
    // cannot be told apart at all. Refusing `_own` would deny a
    // permission the matrix grants; accepting it is honest only while
    // this comment says why. Distinguishing them needs a schema change
    // (uploaded_by) and is tracked separately — the boundary that IS
    // enforced is that a role holding neither code stays out.
    #[RequiresPermission(module: 'asset', action: 'write', anyOf: [
        'asset.write',
        'multimedia.add_edit_own',
        'multimedia.add_edit_any',
    ])]
    public function __invoke(Request $request, string $id): JsonResponse
    {
        $assetId = Uuid::fromString($id);
        // The grid lists CatalogObject rows of `kind=asset`, so the URL
        // segment can be either the Asset.id or the CatalogObject.id
        // (the latter is what `/api/assets` GET hands the frontend).
        // Try the natural lookup first, then fall back to the object_id
        // FK so both forms hit the same row.
        $asset = $this->assets->findById($assetId) ?? $this->assets->findByObjectId($assetId);
        if (null === $asset) {
            throw new NotFoundHttpException(\sprintf('Asset "%s" was not found.', $id));
        }

        if (!$this->authorisation->isGranted('UPDATE', $asset)) {
            throw new AccessDeniedHttpException();
        }

        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            throw new BadRequestHttpException('Request body must be a JSON object.');
        }

        $code = \array_key_exists('code', $payload) ? $this->stringField($payload['code'], 'code') : null;
        $tags = \array_key_exists('tags', $payload) ? $this->tagsField($payload['tags']) : null;

        $this->metadataUpdater->update($asset, code: $code, tags: $tags);

        $refreshed = $this->assets->findById($asset->getId());
        \assert($refreshed instanceof Asset);

        // Mirror the new metadata into the linked CatalogObject so the
        // grid (which reads `attributes_indexed`) reflects the change
        // without waiting for a list-side query to recompute.
        $this->catalogAssetSync->syncFromUploadedAsset(
            assetId: $refreshed->getId(),
            code: $refreshed->getCode(),
            indexedAttributes: [
                'mime' => $refreshed->getMimeType(),
                'filename' => $refreshed->getOriginalFilename(),
                'previewUrl' => \sprintf('/api/assets/%s/preview', $refreshed->getId()->toRfc4122()),
                'thumbnailsStatus' => $refreshed->getThumbnailsStatus()->value,
                'tags' => $refreshed->getTags(),
                'size' => $refreshed->getSize(),
                'width' => $refreshed->getWidth(),
                'height' => $refreshed->getHeight(),
                'pageCount' => $refreshed->getPageCount(),
            ],
        );

        return new JsonResponse($this->present($refreshed), Response::HTTP_OK);
    }

    private function stringField(mixed $value, string $name): string
    {
        if (!\is_string($value) || '' === trim($value)) {
            throw new BadRequestHttpException(\sprintf('Field "%s" must be a non-empty string.', $name));
        }

        return trim($value);
    }

    /**
     * @return array<int, string>
     */
    private function tagsField(mixed $value): array
    {
        if (!\is_array($value)) {
            throw new BadRequestHttpException('Field "tags" must be a JSON array.');
        }
        $tags = [];
        foreach ($value as $entry) {
            if (\is_string($entry)) {
                $tags[] = trim($entry);
            }
        }

        return $tags;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Asset $asset): array
    {
        return [
            'id' => $asset->getId()->toRfc4122(),
            'code' => $asset->getCode(),
            'originalFilename' => $asset->getOriginalFilename(),
            'mimeType' => $asset->getMimeType(),
            'size' => $asset->getSize(),
            'width' => $asset->getWidth(),
            'height' => $asset->getHeight(),
            'pageCount' => $asset->getPageCount(),
            'tags' => $asset->getTags(),
            'thumbnailsStatus' => $asset->getThumbnailsStatus()->value,
        ];
    }
}
