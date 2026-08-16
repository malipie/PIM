<?php

declare(strict_types=1);

namespace App\Import\Presentation\Controller;

use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Domain\Entity\User;
use App\Import\Domain\Entity\ImportProfile;
use App\Import\Domain\Enum\ImportImageSource;
use App\Import\Domain\Enum\ImportMode;
use App\Import\Domain\Repository\ImportProfileRepositoryInterface;
use App\Shared\Application\TenantContext;
use DateTimeInterface;
use JsonException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

use const JSON_THROW_ON_ERROR;

/**
 * VIEW-IMP-02 (#498) — receives the export envelope from
 * {@see ExportImportProfileController} and re-creates the profile
 * under the calling user. Rejects mismatched major schema versions.
 *
 * The receiver re-resolves the target ObjectType by `code` (not id)
 * because uuids are tenant-scoped and would not match across tenants.
 */
final class ImportImportProfileController
{
    /** IMP2-1.3 — pre-ADR-0019 envelopes may carry retired mode values. */
    private const array LEGACY_MODE_MAP = ['ADD' => 'CREATE', 'MERGE' => 'UPSERT', 'INCREMENT' => 'UPSERT', 'DELETE' => 'UPSERT'];

    public function __construct(
        private readonly ImportProfileRepositoryInterface $profiles,
        private readonly ObjectTypeRepositoryInterface $objectTypes,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
    ) {
    }

    #[Route(
        path: '/api/import-profiles/import',
        name: 'imports_profile_import',
        methods: ['POST'],
    )]
    #[RequiresPermission(module: 'import_profile', action: 'write', anyOf: [
        'import_profile.write',
        'imports.run',
    ])]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('JWT', 'Authenticated user required.');
        }
        $tenant = $user->getTenant();
        $this->tenantContext->set($tenant);

        $raw = $request->getContent();
        if ('' === $raw) {
            throw new BadRequestHttpException('Request body cannot be empty.');
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BadRequestHttpException('Request body must be valid JSON.');
        }
        if (!\is_array($decoded)) {
            throw new BadRequestHttpException('Envelope must be a JSON object.');
        }
        /** @var array<string, mixed> $envelope */
        $envelope = $decoded;

        $schemaVersion = $envelope['schemaVersion'] ?? null;
        if (!\is_string($schemaVersion) || !str_starts_with($schemaVersion, '1.')) {
            throw new BadRequestHttpException(\sprintf(
                'Unsupported export schema version "%s". Expected major version 1.',
                \is_string($schemaVersion) ? $schemaVersion : 'null',
            ));
        }

        $profileBlockRaw = $envelope['profile'] ?? null;
        if (!\is_array($profileBlockRaw)) {
            throw new BadRequestHttpException('Envelope is missing the "profile" block.');
        }
        /** @var array<string, mixed> $profileBlock */
        $profileBlock = $profileBlockRaw;

        $name = $this->stringOrFail($profileBlock, 'name');
        $code = $this->stringOrFail($profileBlock, 'code');
        $modeRaw = $this->stringOrFail($profileBlock, 'mode');
        $targetCode = $this->stringOrFail($profileBlock, 'target_object_type_code');

        $mode = ImportMode::tryFrom(self::LEGACY_MODE_MAP[strtoupper($modeRaw)] ?? strtoupper($modeRaw));
        if (!$mode instanceof ImportMode) {
            throw new BadRequestHttpException(\sprintf('Unknown import mode "%s".', $modeRaw));
        }

        $target = $this->objectTypes->findByCode($targetCode, $tenant);
        if (!$target instanceof ObjectType) {
            throw new NotFoundHttpException(\sprintf('ObjectType "%s" was not found in this tenant.', $targetCode));
        }

        if (null !== $this->profiles->findByName($tenant, $user->getId(), $name)) {
            throw new ConflictHttpException(\sprintf('Import profile "%s" already exists for this user.', $name));
        }
        if (null !== $this->profiles->findByCode($tenant, $user->getId(), $code)) {
            throw new ConflictHttpException(\sprintf('Import profile code "%s" already exists for this user.', $code));
        }

        $profile = new ImportProfile(
            userId: $user->getId(),
            name: $name,
            targetObjectType: $target,
            code: $code,
            mode: $mode,
        );
        if (isset($profileBlock['column_mapping']) && \is_array($profileBlock['column_mapping'])) {
            /** @var array<string, string> $mapping */
            $mapping = $profileBlock['column_mapping'];
            $profile->setColumnMapping($mapping);
        }
        $profile->setLocale($this->nullableString($profileBlock, 'locale'));
        $profile->setEncoding($this->nullableString($profileBlock, 'encoding'));
        $profile->setDelimiter($this->nullableString($profileBlock, 'delimiter'));
        $profile->setImageZipNamingConvention($this->nullableString($profileBlock, 'image_zip_naming_convention'));
        $imageSourceRaw = $this->nullableString($profileBlock, 'image_source');
        if (null !== $imageSourceRaw) {
            $imageSource = ImportImageSource::tryFrom($imageSourceRaw);
            if (null !== $imageSource) {
                $profile->setImageSource($imageSource);
            }
        }

        $this->profiles->save($profile);

        return new JsonResponse([
            'id' => $profile->getId()->toRfc4122(),
            'name' => $profile->getName(),
            'code' => $profile->getCode(),
            'mode' => $profile->getMode()->value,
            'created_at' => $profile->getCreatedAt()->format(DateTimeInterface::RFC3339_EXTENDED),
        ], Response::HTTP_CREATED);
    }

    /**
     * @param array<string, mixed> $block
     */
    private function stringOrFail(array $block, string $field): string
    {
        $value = $block[$field] ?? null;
        if (!\is_string($value) || '' === $value) {
            throw new BadRequestHttpException(\sprintf('Field "%s" is required.', $field));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function nullableString(array $block, string $field): ?string
    {
        $value = $block[$field] ?? null;
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        return $value;
    }
}
