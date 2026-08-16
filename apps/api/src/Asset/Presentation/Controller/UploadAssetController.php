<?php

declare(strict_types=1);

namespace App\Asset\Presentation\Controller;

use App\Asset\Application\AssetUploader;
use App\Asset\Application\Exception\DuplicateAssetException;
use App\Asset\Application\MimeTypeWhitelist;
use App\Asset\Domain\Entity\Asset;
use App\Catalog\Contracts\Service\ProductAssetLinker;
use App\Identity\Contracts\Attribute\RequiresPermission;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /api/assets/upload` (multipart) — DAM MVP entry point (#438).
 *
 * AP4 owns `GET /api/assets[/{id}]` via the read-only operations on
 * {@see \App\Catalog\Domain\Entity\CatalogObject}; multipart uploads
 * cannot ride on the same path without colliding with AP4's GET
 * collection, so the write surface lives at `/api/assets/upload`.
 *
 * Multipart form fields:
 *   - file (binary, required)
 *   - code (optional — auto-slugged from filename when missing)
 *   - tags[] (optional repeated form field, also accepts comma list)
 *
 * Responses:
 *   - 201 Created → asset payload with `id`, `code`, `mimeType`,
 *     `size`, `width`, `height`, `tags`, `thumbnailsStatus`
 *   - 400 Bad Request — missing `file`
 *   - 409 Conflict — duplicate hash; body carries `existingAssetId`
 *   - 413 Payload Too Large — exceeds per-MIME limit (image vs PDF)
 *   - 415 Unsupported Media Type — MIME outside the whitelist
 */
final class UploadAssetController
{
    public function __construct(
        private readonly AssetUploader $uploader,
        private readonly AuthorizationCheckerInterface $authorisation,
        private readonly ProductAssetLinker $productAssetLinker,
        private readonly int $maxImageBytes,
        private readonly int $maxPdfBytes,
    ) {
    }

    #[Route(path: '/api/assets/upload', name: 'pim_assets_upload', methods: ['POST'], format: 'json')]
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
    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->authorisation->isGranted('CREATE', Asset::class)) {
            throw new AccessDeniedHttpException();
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new BadRequestHttpException('Multipart field "file" is required.');
        }

        if (!$file->isValid()) {
            throw new BadRequestHttpException(\sprintf('Uploaded file is invalid: %s', $file->getErrorMessage()));
        }

        $mimeType = $file->getMimeType() ?? 'application/octet-stream';
        if (!MimeTypeWhitelist::isAccepted($mimeType)) {
            throw new UnsupportedMediaTypeHttpException(\sprintf(
                'MIME type "%s" is not accepted. Allowed: %s.',
                $mimeType,
                implode(', ', MimeTypeWhitelist::all()),
            ));
        }

        $size = $file->getSize();
        $limit = MimeTypeWhitelist::isPdf($mimeType) ? $this->maxPdfBytes : $this->maxImageBytes;
        if (false !== $size && $size > $limit) {
            throw new HttpException(
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
                \sprintf('Uploaded file (%d bytes) exceeds %d-byte limit for %s.', $size, $limit, $mimeType),
            );
        }

        $code = $request->request->get('code');
        $tags = $this->normaliseTags($request);
        $folderCode = $this->normaliseFolderCode($request);

        try {
            $asset = $this->uploader->upload(
                $file,
                \is_string($code) && '' !== trim($code) ? trim($code) : null,
                $tags,
                $folderCode,
            );
        } catch (DuplicateAssetException $e) {
            return new JsonResponse([
                'type' => 'urn:pim:asset:duplicate',
                'title' => 'Asset already exists',
                'detail' => $e->getMessage(),
                'existingAssetId' => $e->existingAssetId->toRfc4122(),
                'existingCode' => $e->existingCode,
            ], Response::HTTP_CONFLICT, ['Content-Type' => 'application/problem+json']);
        } catch (FileException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }

        // Auto-link to product when uploaded inside a product card —
        // the frontend builds `folderCode = "product-<UUID>"` so the
        // backend can resolve the parent without a second request.
        if (null !== $folderCode) {
            $productId = $this->extractProductIdFromFolder($folderCode);
            if (null !== $productId) {
                $this->productAssetLinker->linkAssetsToProduct($productId, [$asset->getId()]);
            }
        }

        return new JsonResponse($this->present($asset), Response::HTTP_CREATED);
    }

    private function normaliseFolderCode(Request $request): ?string
    {
        $raw = $request->request->get('folderCode');
        if (!\is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw);

        return '' === $trimmed ? null : $trimmed;
    }

    private function extractProductIdFromFolder(string $folderCode): ?Uuid
    {
        if (!str_starts_with($folderCode, 'product-')) {
            return null;
        }
        $candidate = substr($folderCode, \strlen('product-'));
        try {
            return Uuid::fromString($candidate);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @return array<int, string>
     */
    private function normaliseTags(Request $request): array
    {
        $raw = $request->request->all('tags');
        if ([] === $raw) {
            $single = $request->request->get('tags');
            if (\is_string($single) && '' !== trim($single)) {
                $raw = explode(',', $single);
            }
        }

        $tags = [];
        foreach ($raw as $tag) {
            if (\is_string($tag)) {
                $trimmed = trim($tag);
                if ('' !== $trimmed) {
                    $tags[] = $trimmed;
                }
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
            'storagePath' => $asset->getStoragePath(),
        ];
    }
}
