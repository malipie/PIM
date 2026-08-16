<?php

declare(strict_types=1);

namespace App\Asset\Presentation\Controller;

use App\Asset\Application\AssetDeleter;
use App\Asset\Domain\Repository\AssetRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * `DELETE /api/assets/{id}` (#438) — single-asset delete with cascade
 * to storage objects (original + variants).
 */
final readonly class DeleteAssetController
{
    public function __construct(
        private AssetRepositoryInterface $assets,
        private AssetDeleter $deleter,
        private AuthorizationCheckerInterface $authorisation,
    ) {
    }

    #[Route(path: '/api/assets/{id}', name: 'pim_assets_delete', methods: ['DELETE'], format: 'json')]
    #[RequiresPermission(module: 'asset', action: 'delete', anyOf: [
        'asset.delete',
        'multimedia.delete',
    ])]
    public function __invoke(string $id): JsonResponse
    {
        $assetId = Uuid::fromString($id);
        $asset = $this->assets->findById($assetId) ?? $this->assets->findByObjectId($assetId);
        if (null === $asset) {
            throw new NotFoundHttpException(\sprintf('Asset "%s" was not found.', $id));
        }

        if (!$this->authorisation->isGranted('DELETE', $asset)) {
            throw new AccessDeniedHttpException();
        }

        $this->deleter->delete($asset);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
