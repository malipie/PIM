<?php

declare(strict_types=1);

namespace App\Import\Presentation\Controller;

use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeAttributeRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Import\Application\Service\AutoMapper;
use App\Import\Domain\ReservedMappingTarget;
use App\Import\Domain\ValueObject\ColumnMappingSuggestion;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * IMP-02 (#443) — frontend Step 2 calls this with the parsed file's
 * headers + sample values + target ObjectType id, and gets back a
 * suggestion list it renders into the mapping table.
 */
final class AutoMapController
{
    public function __construct(
        private readonly AutoMapper $autoMapper,
        private readonly ObjectTypeRepositoryInterface $objectTypes,
        private readonly ObjectTypeAttributeRepositoryInterface $objectTypeAttributes,
    ) {
    }

    #[Route(
        path: '/api/import-sessions/auto-map',
        name: 'imports_auto_map',
        methods: ['POST'],
    )]
    #[RequiresPermission(module: 'import_session', action: 'write', anyOf: [
        'import_session.write',
        'imports.run',
    ])]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            throw new BadRequestHttpException('Body must be a JSON object.');
        }

        $headers = $payload['column_headers'] ?? null;
        if (!\is_array($headers) || [] === $headers) {
            throw new BadRequestHttpException('column_headers (non-empty list) is required.');
        }

        $sampleValues = $payload['sample_values'] ?? [];
        if (!\is_array($sampleValues)) {
            throw new BadRequestHttpException('sample_values must be a list of rows.');
        }

        $rawTargetId = $payload['target_object_type_id'] ?? null;
        if (!\is_string($rawTargetId) || '' === $rawTargetId) {
            throw new BadRequestHttpException('target_object_type_id is required.');
        }

        try {
            $targetId = Uuid::fromString($rawTargetId);
        } catch (InvalidArgumentException) {
            throw new BadRequestHttpException(\sprintf('Invalid target_object_type_id "%s".', $rawTargetId));
        }

        $objectType = $this->objectTypes->findById($targetId);
        if (!$objectType instanceof ObjectType) {
            throw new NotFoundHttpException(\sprintf('ObjectType "%s" was not found.', $rawTargetId));
        }

        $availableCodes = [];
        $labelsByCode = [];
        foreach ($this->objectTypeAttributes->findByObjectType($objectType) as $junction) {
            $attribute = $junction->getAttribute();
            $code = $attribute->getCode();
            $availableCodes[] = $code;
            // Localised labels ({pl,en,…}) feed the name-based matcher so
            // hand-built files with human-readable headers auto-map too.
            $labelsByCode[$code] = array_values(array_filter(
                $attribute->getLabel(),
                static fn (string $label): bool => '' !== trim($label),
            ));
        }
        // Category assignment is supported only for product imports —
        // expose the reserved __category__ target so the dictionary's
        // category aliases (kategoria, group, …) resolve to it.
        if (ObjectKind::Product === $objectType->getKind()) {
            $availableCodes[] = ReservedMappingTarget::CATEGORY;
        }

        $normalisedHeaders = [];
        foreach ($headers as $header) {
            $normalisedHeaders[] = \is_scalar($header) ? (string) $header : '';
        }

        $normalisedRows = [];
        foreach ($sampleValues as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $cells = [];
            foreach ($row as $cell) {
                if (null === $cell) {
                    $cells[] = null;
                } else {
                    $cells[] = \is_scalar($cell) ? (string) $cell : '';
                }
            }
            $normalisedRows[] = $cells;
        }

        $suggestions = $this->autoMapper->map($availableCodes, $normalisedHeaders, $normalisedRows, $labelsByCode);

        return new JsonResponse(
            [
                'mappings' => array_map(
                    static fn (ColumnMappingSuggestion $suggestion): array => [
                        'column_index' => $suggestion->columnIndex,
                        'column_header' => $suggestion->columnHeader,
                        'suggested_attribute_code' => $suggestion->suggestedAttributeCode,
                        'confidence' => $suggestion->confidence->value,
                        'sample_values' => $suggestion->sampleValues,
                        'alternative_attribute_code' => $suggestion->alternativeAttributeCode,
                    ],
                    $suggestions,
                ),
            ],
            Response::HTTP_OK,
        );
    }
}
