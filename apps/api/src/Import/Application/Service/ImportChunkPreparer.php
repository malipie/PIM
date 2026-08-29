<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Asset\Domain\Repository\AssetRepositoryInterface;
use App\Catalog\Application\BatchValueWriter;
use App\Catalog\Application\CrossFieldRulesValidator;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Import\Application\Service\Media\AssetUrlResolver;
use App\Import\Domain\Entity\ImportSession;
use App\Import\Domain\Enum\ImportErrorType;
use App\Import\Domain\Enum\ImportLogLevel;
use App\Import\Domain\ReservedMappingTarget;
use App\Import\Domain\SystemColumn;
use App\Import\Domain\ValueObject\ResolvedImportValue;
use App\Import\Domain\ValueObject\ValidationError;
use App\Shared\Domain\Tenant;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Throwable;

/**
 * #3024 — prepares one import chunk for the handler's write phase.
 *
 * Validation, value materialisation, match-key resolution and identifier
 * collision checks form one read-only decision boundary. The handler retains
 * transaction/checkpoint ownership and all create/update/skip writes.
 */
final readonly class ImportChunkPreparer
{
    public function __construct(
        private ImportValidationService $validator,
        private ImportColumnGrammar $columnGrammar,
        private ImportRowCells $rowCells,
        private ObjectResolver $objectResolver,
        private ImportCategoryOps $categoryOps,
        private BatchValueWriter $valueWriter,
        private ImportUndoLogger $undoLogger,
        private EntityManagerInterface $entityManager,
        private CrossFieldRulesValidator $crossFieldRules,
        private AttributeRepositoryInterface $attributeRepository,
        private AssetRepositoryInterface $assets,
        private AssetUrlResolver $assetUrlResolver,
    ) {
    }

    /**
     * @param list<array{rowNumber: int, cells: array<string, string|null>}> $buffer
     * @param array<string, string>                                          $columnMapping
     * @param array<string, Attribute>                                       $attributesByCode
     * @param array<string, int>                                             $skuSeenInFile
     * @param array<string, array<string, int>>                              $identifierSeenInFile
     *
     * @return ImportChunkPreparation<CatalogObject>
     */
    public function prepare(
        ImportSession $session,
        Tenant $tenant,
        array $buffer,
        array $columnMapping,
        array $attributesByCode,
        array &$skuSeenInFile,
        array &$identifierSeenInFile,
    ): ImportChunkPreparation {
        $target = $session->getTargetObjectType();
        if (null === $target) {
            throw new RuntimeException('Import session requires a target object type.');
        }
        $identityAttributeCode = $this->validator->identityAttributeCode(
            $target,
            $session->getMatchAttributeCode(),
        );

        /** @var list<array{rowNumber: int, cells: array<string, string|null>, sku: ?string, errors: list<ValidationError>, rowOk: bool, resolvedValues: list<ResolvedImportValue>, matchKey: string, duplicateInFile: bool}> $prepared */
        $prepared = [];
        $matchKeys = [];

        foreach ($buffer as $entry) {
            $rowNumber = $entry['rowNumber'];
            $cells = $entry['cells'];
            $errors = $this->validator->validateRow(
                rowNumber: $rowNumber,
                cells: $cells,
                columnMapping: $columnMapping,
                attributesByCode: $attributesByCode,
                tenant: $tenant,
                skuSeenInFile: $skuSeenInFile,
                identityAttributeCode: $identityAttributeCode,
            );
            $sku = $cells[$this->rowCells->skuColumnHeader($columnMapping)] ?? null;
            $blocking = array_values(array_filter(
                $errors,
                static fn (ValidationError $error): bool => $error->isRowBlocking(),
            ));
            $duplicateInFile = [] !== array_filter(
                $errors,
                static fn (ValidationError $error): bool => ImportErrorType::DuplicateSkuInFile === $error->errorType,
            );
            $rowOk = [] === $blocking && !$duplicateInFile;
            $resolvedValues = [];

            if ($rowOk) {
                try {
                    $resolvedValues = $this->materialiseValues($cells, $columnMapping, $tenant);
                } catch (Throwable $exception) {
                    $rowOk = false;
                    $errors[] = new ValidationError(
                        rowNumber: $rowNumber,
                        sku: $sku,
                        errorType: ImportErrorType::InvalidValue,
                        level: ImportLogLevel::Error,
                        message: 'Row could not be parsed: '.$exception->getMessage(),
                        columnValue: $this->rowCells->rawRowSnippet($cells),
                    );
                }
            }

            $matchKey = $rowOk
                ? $this->matchKey($session, $cells, $columnMapping, $resolvedValues, $rowNumber)
                : '';
            if ('' !== $matchKey) {
                $matchKeys[] = $matchKey;
            }
            $prepared[] = [
                'rowNumber' => $rowNumber,
                'cells' => $cells,
                'sku' => $sku,
                'errors' => $errors,
                'rowOk' => $rowOk,
                'resolvedValues' => $resolvedValues,
                'matchKey' => $matchKey,
                'duplicateInFile' => $duplicateInFile,
            ];
        }

        $existingByKey = $this->objectResolver->resolveMany(
            $matchKeys,
            $target,
            $tenant,
            $session->getMatchAttributeCode(),
        );
        $this->valueWriter->primeChunk(array_values($existingByKey), $attributesByCode);
        $this->undoLogger->primeChunk(array_values($existingByKey));
        $categoryByCode = $this->categoryOps->resolveChunkCategories($prepared, $columnMapping, $tenant);
        $existingAssetIds = $this->resolveChunkAssets($prepared, $attributesByCode, $tenant);
        $this->precheckIdentifiers(
            $prepared,
            $attributesByCode,
            $existingByKey,
            $target->getId()->toRfc4122(),
            $tenant,
            $identifierSeenInFile,
        );

        return new ImportChunkPreparation($prepared, $existingByKey, $categoryByCode, $existingAssetIds);
    }

    /**
     * @param array<string, string> $columnMapping
     *
     * @return array<string, Attribute>
     */
    public function loadAttributesForSession(
        ImportSession $session,
        Tenant $tenant,
        array $columnMapping,
    ): array {
        $attributes = $this->validator->loadAttributesByCode($tenant, $columnMapping);
        $target = $session->getTargetObjectType();
        if (null === $target) {
            return $attributes;
        }

        foreach ($this->crossFieldRules->rulesFor($target) as $rule) {
            foreach ($rule->referencedCodes() as $code) {
                if (isset($attributes[$code])) {
                    continue;
                }
                $attribute = $this->attributeRepository->findByCode($code, $tenant);
                if ($attribute instanceof Attribute) {
                    $attributes[$code] = $attribute;
                }
            }
        }

        return $attributes;
    }

    /**
     * @param list<array{rowNumber: int, cells: array<string, string|null>, sku: ?string, errors: list<ValidationError>, rowOk: bool, resolvedValues: list<ResolvedImportValue>, matchKey: string, duplicateInFile: bool}> $prepared
     * @param array<string, Attribute>                                                                                                                                                                                     $attributesByCode
     *
     * @return array<string, true>
     */
    private function resolveChunkAssets(array $prepared, array $attributesByCode, Tenant $tenant): array
    {
        $ids = [];
        foreach ($prepared as $row) {
            if (!$row['rowOk']) {
                continue;
            }
            foreach ($row['resolvedValues'] as $resolved) {
                $attribute = $attributesByCode[$resolved->attributeCode] ?? null;
                if (!$attribute instanceof Attribute || AttributeType::Asset !== $attribute->getType()) {
                    continue;
                }
                $raw = $resolved->rawValue;
                if (null === $raw || '' === $raw) {
                    continue;
                }
                foreach ($this->assetUrlResolver->classify($raw)['uuids'] as $id) {
                    $ids[$id] = true;
                }
            }
        }

        if ([] === $ids) {
            return [];
        }

        $existingIds = [];
        foreach ($this->assets->existingIds(array_keys($ids), $tenant) as $existing) {
            $existingIds[strtolower($existing)] = true;
        }

        return $existingIds;
    }

    /**
     * @param array<string, string|null> $cells
     * @param array<string, string>      $columnMapping
     *
     * @return list<ResolvedImportValue>
     */
    private function materialiseValues(array $cells, array $columnMapping, Tenant $tenant): array
    {
        $out = [];
        foreach ($columnMapping as $columnHeader => $attributeCode) {
            if (SystemColumn::isSystem($columnHeader)) {
                continue;
            }
            if ('' === $attributeCode || ReservedMappingTarget::isReserved($attributeCode)) {
                continue;
            }
            $parsed = $this->columnGrammar->parse($columnHeader, $tenant);
            if (null !== $parsed->unknownSuffix) {
                continue;
            }
            $out[] = new ResolvedImportValue(
                attributeCode: $attributeCode,
                locale: $parsed->locale,
                rawValue: $cells[$columnHeader] ?? null,
                channelId: $parsed->channelId,
            );
        }

        return $out;
    }

    /**
     * @param list<array{rowNumber: int, cells: array<string, string|null>, sku: ?string, errors: list<ValidationError>, rowOk: bool, resolvedValues: list<ResolvedImportValue>, matchKey: string, duplicateInFile: bool}> $prepared
     * @param array<string, Attribute>                                                                                                                                                                                     $attributesByCode
     * @param array<string, CatalogObject>                                                                                                                                                                                 $existingByKey
     * @param array<string, array<string, int>>                                                                                                                                                                            $identifierSeenInFile
     */
    private function precheckIdentifiers(
        array &$prepared,
        array $attributesByCode,
        array $existingByKey,
        string $targetObjectTypeId,
        Tenant $tenant,
        array &$identifierSeenInFile,
    ): void {
        $identifierAttributes = [];
        foreach ($attributesByCode as $code => $attribute) {
            if (AttributeType::Identifier === $attribute->getType()) {
                $identifierAttributes[$code] = $attribute;
            }
        }
        if ([] === $identifierAttributes) {
            return;
        }

        /** @var array<string, array<string, true>> $valuesByAttributeId */
        $valuesByAttributeId = [];
        foreach ($prepared as $row) {
            if (!$row['rowOk']) {
                continue;
            }
            foreach ($row['resolvedValues'] as $resolved) {
                $attribute = $identifierAttributes[$resolved->attributeCode] ?? null;
                $value = $resolved->rawValue;
                if (null === $attribute || null === $value || '' === $value) {
                    continue;
                }
                $valuesByAttributeId[$attribute->getId()->toRfc4122()][$value] = true;
            }
        }

        $existingOwners = $this->fetchIdentifierOwners(
            $tenant,
            $targetObjectTypeId,
            $valuesByAttributeId,
        );

        foreach ($prepared as $index => $row) {
            if (!$row['rowOk']) {
                continue;
            }
            $ownObjectId = ($existingByKey[$row['matchKey']] ?? null)?->getId()->toRfc4122();
            foreach ($row['resolvedValues'] as $resolved) {
                $attribute = $identifierAttributes[$resolved->attributeCode] ?? null;
                $value = $resolved->rawValue;
                if (null === $attribute || null === $value || '' === $value) {
                    continue;
                }
                $attributeCode = $resolved->attributeCode;
                $attributeId = $attribute->getId()->toRfc4122();

                $owner = $existingOwners[$attributeId][$value] ?? null;
                if (null !== $owner && $owner !== $ownObjectId) {
                    $prepared[$index]['rowOk'] = false;
                    $prepared[$index]['errors'][] = new ValidationError(
                        rowNumber: $row['rowNumber'],
                        sku: $row['sku'],
                        errorType: ImportErrorType::InvalidValue,
                        level: ImportLogLevel::Error,
                        message: \sprintf('Identifier "%s" = "%s" is already used by another object.', $attributeCode, $value),
                        columnName: $attributeCode,
                        columnValue: $value,
                    );

                    continue 2;
                }

                if (isset($identifierSeenInFile[$attributeCode][$value])) {
                    $prepared[$index]['rowOk'] = false;
                    $prepared[$index]['duplicateInFile'] = true;
                    $prepared[$index]['errors'][] = new ValidationError(
                        rowNumber: $row['rowNumber'],
                        sku: $row['sku'],
                        errorType: ImportErrorType::DuplicateSkuInFile,
                        level: ImportLogLevel::Warning,
                        message: \sprintf('Identifier "%s" = "%s" already appeared in the file at row %d — skipped.', $attributeCode, $value, $identifierSeenInFile[$attributeCode][$value]),
                        columnName: $attributeCode,
                        columnValue: $value,
                    );

                    continue 2;
                }

                $identifierSeenInFile[$attributeCode][$value] = $row['rowNumber'];
            }
        }
    }

    /**
     * @param array<string, array<string, true>> $valuesByAttributeId
     *
     * @return array<string, array<string, string>>
     */
    private function fetchIdentifierOwners(Tenant $tenant, string $objectTypeId, array $valuesByAttributeId): array
    {
        if ([] === $valuesByAttributeId) {
            return [];
        }
        $allValues = [];
        foreach ($valuesByAttributeId as $values) {
            foreach (array_keys($values) as $value) {
                $allValues[$value] = true;
            }
        }

        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT ov.attribute_id AS attribute_id, ov.value->>\'value\' AS ident, ov.object_id AS object_id'
            .' FROM object_values ov JOIN objects o ON o.id = ov.object_id'
            .' WHERE ov.tenant_id = :tenant AND o.object_type_id = :ot'
            .' AND ov.attribute_id IN (:attrs) AND ov.value->>\'value\' IN (:vals)',
            [
                'tenant' => $tenant->getId()->toRfc4122(),
                'ot' => $objectTypeId,
                'attrs' => array_keys($valuesByAttributeId),
                'vals' => array_keys($allValues),
            ],
            [
                'attrs' => ArrayParameterType::STRING,
                'vals' => ArrayParameterType::STRING,
            ],
        );

        $map = [];
        foreach ($rows as $row) {
            $attributeId = $row['attribute_id'];
            $identifier = $row['ident'];
            $objectId = $row['object_id'];
            if (!\is_scalar($attributeId) || !\is_scalar($identifier) || !\is_scalar($objectId)) {
                continue;
            }
            $map[(string) $attributeId][(string) $identifier] = (string) $objectId;
        }

        return $map;
    }

    /**
     * @param array<string, string|null> $cells
     * @param array<string, string>      $columnMapping
     * @param list<ResolvedImportValue>  $resolvedValues
     */
    private function matchKey(
        ImportSession $session,
        array $cells,
        array $columnMapping,
        array $resolvedValues,
        int $rowNumber,
    ): string {
        $matchCode = $session->getMatchAttributeCode();
        if (null !== $matchCode) {
            foreach ($columnMapping as $header => $target) {
                if ($target === $matchCode) {
                    return trim($cells[$header] ?? '');
                }
            }

            return '';
        }

        return trim($this->rowCells->skuFrom($resolvedValues, $rowNumber));
    }
}
