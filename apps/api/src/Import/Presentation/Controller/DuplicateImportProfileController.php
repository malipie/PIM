<?php

declare(strict_types=1);

namespace App\Import\Presentation\Controller;

use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Contracts\Auth\CurrentUserProvider;
use App\Import\Domain\Entity\ImportProfile;
use App\Import\Domain\Repository\ImportProfileRepositoryInterface;
use DateTimeInterface;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * VIEW-IMP-02 (#498) — clone an existing profile under a `*_copy`
 * suffix so the operator can tweak the mapping without losing the
 * original. The new row is owned by the calling user; name + code
 * collisions resolve by appending `_copy2`, `_copy3`, etc.
 */
final class DuplicateImportProfileController
{
    private const int MAX_COPY_ATTEMPTS = 50;

    public function __construct(
        private readonly ImportProfileRepositoryInterface $profiles,
        private readonly CurrentUserProvider $currentUser,
    ) {
    }

    #[Route(
        path: '/api/import-profiles/{id}/duplicate',
        name: 'imports_profile_duplicate',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    #[RequiresPermission(module: 'import_profile', action: 'write')]
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

        $source = $this->profiles->findById($profileId);
        if (!$source instanceof ImportProfile) {
            throw new NotFoundHttpException(\sprintf('Import profile "%s" was not found.', $id));
        }
        if ($source->getUserId()->toRfc4122() !== $userId->toRfc4122()) {
            throw new NotFoundHttpException(\sprintf('Import profile "%s" was not found.', $id));
        }

        $tenant = $source->getTenant();
        if (null === $tenant) {
            throw new NotFoundHttpException('Profile is not bound to a tenant.');
        }

        $name = $this->uniqueName($source->getName(), $tenant, $userId);
        $code = $this->uniqueCode(ImportProfile::slugify($name), $tenant, $userId);

        $clone = new ImportProfile(
            userId: $userId,
            name: $name,
            targetObjectType: $source->getTargetObjectType(),
            code: $code,
            mode: $source->getMode(),
        );
        $clone->setColumnMapping($source->getColumnMapping());
        $clone->setLocale($source->getLocale());
        $clone->setEncoding($source->getEncoding());
        $clone->setDelimiter($source->getDelimiter());
        $clone->setImageSource($source->getImageSource());
        $clone->setImageZipNamingConvention($source->getImageZipNamingConvention());

        $this->profiles->save($clone);

        return new JsonResponse([
            'id' => $clone->getId()->toRfc4122(),
            'name' => $clone->getName(),
            'code' => $clone->getCode(),
            'mode' => $clone->getMode()->value,
            'created_at' => $clone->getCreatedAt()->format(DateTimeInterface::RFC3339_EXTENDED),
        ], Response::HTTP_CREATED);
    }

    private function uniqueName(string $base, \App\Shared\Domain\Tenant $tenant, Uuid $userId): string
    {
        $candidate = $base.' (copy)';
        for ($i = 2; $i <= self::MAX_COPY_ATTEMPTS; ++$i) {
            if (null === $this->profiles->findByName($tenant, $userId, $candidate)) {
                return $candidate;
            }
            $candidate = \sprintf('%s (copy %d)', $base, $i);
        }
        throw new RuntimeException('Cannot find a free duplicate name slot after 50 attempts.');
    }

    private function uniqueCode(string $base, \App\Shared\Domain\Tenant $tenant, Uuid $userId): string
    {
        $candidate = $base.'-copy';
        for ($i = 2; $i <= self::MAX_COPY_ATTEMPTS; ++$i) {
            if (null === $this->profiles->findByCode($tenant, $userId, $candidate)) {
                return $candidate;
            }
            $candidate = \sprintf('%s-copy-%d', $base, $i);
        }
        throw new RuntimeException('Cannot find a free duplicate code slot after 50 attempts.');
    }
}
