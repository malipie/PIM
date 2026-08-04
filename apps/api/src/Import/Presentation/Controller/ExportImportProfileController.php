<?php

declare(strict_types=1);

namespace App\Import\Presentation\Controller;

use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Contracts\Auth\CurrentUserProvider;
use App\Import\Domain\Entity\ImportProfile;
use App\Import\Domain\Repository\ImportProfileRepositoryInterface;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * VIEW-IMP-02 (#498) — emits a portable JSON envelope of a profile.
 *
 * Schema is versioned (`schemaVersion`) so the matching import
 * endpoint can reject incompatible payloads. We never serialize
 * tenant or user fields — the receiver re-stamps those from its own
 * security token.
 */
final class ExportImportProfileController
{
    public const string SCHEMA_VERSION = '1.0';

    public function __construct(
        private readonly ImportProfileRepositoryInterface $profiles,
        private readonly CurrentUserProvider $currentUser,
    ) {
    }

    #[Route(
        path: '/api/import-profiles/{id}/export',
        name: 'imports_profile_export',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    #[RequiresPermission(module: 'import_profile', action: 'read')]
    public function __invoke(string $id): JsonResponse
    {
        $userId = $this->currentUser->userId();
        if (null === $userId) {
            throw new UnauthorizedHttpException('JWT', 'Authenticated user required.');
        }

        try {
            $profileId = Uuid::fromString($id);
        } catch (InvalidArgumentException) {
            throw new NotFoundHttpException(\sprintf('Import profile "%s" was not found.', $id));
        }

        $profile = $this->profiles->findById($profileId);
        if (!$profile instanceof ImportProfile) {
            throw new NotFoundHttpException(\sprintf('Import profile "%s" was not found.', $id));
        }
        if ($profile->getUserId()->toRfc4122() !== $userId->toRfc4122()) {
            throw new NotFoundHttpException(\sprintf('Import profile "%s" was not found.', $id));
        }

        $payload = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'exportedAt' => new DateTimeImmutable()->format(DateTimeInterface::RFC3339_EXTENDED),
            'profile' => [
                'name' => $profile->getName(),
                'code' => $profile->getCode(),
                'mode' => $profile->getMode()->value,
                'target_object_type_code' => $profile->getTargetObjectType()->getCode(),
                'column_mapping' => $profile->getColumnMapping(),
                'locale' => $profile->getLocale(),
                'encoding' => $profile->getEncoding(),
                'delimiter' => $profile->getDelimiter(),
                'image_source' => $profile->getImageSource()->value,
                'image_zip_naming_convention' => $profile->getImageZipNamingConvention(),
            ],
        ];

        $response = new JsonResponse($payload, Response::HTTP_OK);
        $response->headers->set(
            'Content-Disposition',
            \sprintf('attachment; filename="import-profile-%s.json"', $profile->getCode()),
        );

        return $response;
    }
}
