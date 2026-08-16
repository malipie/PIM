<?php

declare(strict_types=1);

namespace App\Asset\Presentation\Controller;

use App\Asset\Application\AssetDeleter;
use App\Asset\Domain\Repository\AssetRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /api/assets/bulk-delete` (#438) — batch delete up to 100
 * assets in one round-trip. Returns 207 Multi-Status with a per-id
 * outcome (`deleted` | `not_found` | `forbidden`) so the UI can keep
 * surviving rows visible.
 */
final readonly class BulkDeleteAssetsController
{
    private const MAX_BATCH = 100;

    public function __construct(
        private AssetRepositoryInterface $assets,
        private AssetDeleter $deleter,
        private AuthorizationCheckerInterface $authorisation,
    ) {
    }

    #[Route(path: '/api/assets/bulk-delete', name: 'pim_assets_bulk_delete', methods: ['POST'], format: 'json')]
    #[RequiresPermission(module: 'asset', action: 'delete', anyOf: [
        'asset.delete',
        'multimedia.delete',
    ])]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload) || !\is_array($payload['ids'] ?? null)) {
            throw new BadRequestHttpException('Body must be { "ids": [string, ...] }.');
        }

        $ids = $payload['ids'];
        if ([] === $ids) {
            throw new BadRequestHttpException('At least one id is required.');
        }
        if (\count($ids) > self::MAX_BATCH) {
            throw new BadRequestHttpException(\sprintf('Bulk delete is limited to %d ids per request.', self::MAX_BATCH));
        }

        $results = [];
        foreach ($ids as $rawId) {
            if (!\is_string($rawId)) {
                $results[] = ['id' => $rawId, 'status' => 'invalid'];
                continue;
            }
            try {
                $assetId = Uuid::fromString($rawId);
            } catch (InvalidArgumentException) {
                $results[] = ['id' => $rawId, 'status' => 'invalid'];
                continue;
            }

            $asset = $this->assets->findById($assetId) ?? $this->assets->findByObjectId($assetId);
            if (null === $asset) {
                $results[] = ['id' => $rawId, 'status' => 'not_found'];
                continue;
            }
            if (!$this->authorisation->isGranted('DELETE', $asset)) {
                $results[] = ['id' => $rawId, 'status' => 'forbidden'];
                continue;
            }

            $this->deleter->delete($asset);
            $results[] = ['id' => $rawId, 'status' => 'deleted'];
        }

        return new JsonResponse(['results' => $results], Response::HTTP_MULTI_STATUS);
    }
}
