<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Contracts\Query\ObjectTypeAttributeCodesPort;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Import\Domain\Enum\FileEncoding;
use App\Import\Domain\Enum\ImportErrorType;
use App\Import\Domain\Enum\ImportLogLevel;
use App\Import\Domain\ReservedMappingTarget;
use App\Import\Domain\SystemColumn;
use App\Import\Domain\ValueObject\ValidationError;
use App\Import\Domain\ValueObject\ValidationResult;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use LogicException;

/**
 * Iterates the uploaded file row-by-row and produces per-row validation
 * findings without persisting anything. Powers the wizard's Step 3
 * preview ("247 OK, 33 errors") and the post-async report flow in IMP-05.
 *
 * Required + unique fields per spec §7.5 — hardcoded to `sku` + `name`
 * (required) and `sku` + `ean` (unique-in-file). DB-side uniqueness on
 * SKU is checked against {@see CatalogObjectRepositoryInterface} scoped
 * to the active tenant. Custom validation rules + cross-attribute
 * checks are out of MVP scope (spec §7.5).
 */
final readonly class ImportValidationService
{
    private const string SKU_ATTRIBUTE_CODE = 'sku';

    private const int SAMPLE_LIMIT = 100;

    public function __construct(
        private AttributeRepositoryInterface $attributes,
        private CatalogObjectRepositoryInterface $catalogObjects,
        private TenantContext $tenantContext,
        private ImportRowReader $rowReader,
        private CompositeValueParser $compositeValueParser,
        private ImportColumnGrammar $columnGrammar,
        private ObjectTypeAttributeCodesPort $objectTypeAttributeCodes,
    ) {
    }

    /**
     * #2943 — which column carries the row's identity, or null when the
     * target models none.
     *
     * `sku` used to be required unconditionally. For a custom ObjectType that
     * does not model it — the reported case was a "Twórcy" module — every row
     * failed with `Missing required attribute "sku"` for a column the operator
     * had no way to supply. The write path never needed it: `skuFrom()` falls
     * back to `IMPORT-<row>` for `objects.code`, so only this check stood in
     * the way.
     *
     * Resolution order:
     *   1. the run's match attribute — that is the key rows are matched on,
     *      so a row without it cannot be resolved (ADR-0019 D1);
     *   2. `sku`, but only when the target actually models it — the same
     *      junction the mapping step offers as targets, read across the BC
     *      boundary through {@see ObjectTypeAttributeCodesPort};
     *   3. nothing.
     */
    public function identityAttributeCode(ObjectType $target, ?string $matchAttributeCode): ?string
    {
        if (null !== $matchAttributeCode && '' !== $matchAttributeCode) {
            return $matchAttributeCode;
        }

        $modelled = $this->objectTypeAttributeCodes->forObjectType($target->getId());

        return \in_array(self::SKU_ATTRIBUTE_CODE, $modelled, true) ? self::SKU_ATTRIBUTE_CODE : null;
    }

    /**
     * @param array<string, string> $columnMapping header → attribute_code (or "skip")
     */
    public function validate(
        string $absolutePath,
        array $columnMapping,
        ObjectType $target,
        ?FileEncoding $encodingOverride = null,
        ?string $delimiterOverride = null,
        ?int $maxRows = null,
        ?string $matchAttributeCode = null,
    ): ValidationResult {
        $tenant = $this->tenantContext->get();
        if (!$tenant instanceof Tenant) {
            throw new LogicException('Tenant context must be set for import validation.');
        }

        $attributesByCode = $this->loadAttributesByCode($tenant, $columnMapping);
        $identityAttributeCode = $this->identityAttributeCode($target, $matchAttributeCode);
        $errors = [];
        $successCount = 0;
        $errorCount = 0;
        $totalRows = 0;
        $skuSeenInFile = [];

        $truncated = false;

        foreach ($this->rowReader->read($absolutePath, $encodingOverride, $delimiterOverride) as $rowNumber => $cells) {
            // #2810 — a dry-run is a sanity check before committing, not a
            // full pass: reading 51 800 rows took over 30 s and came back as
            // a PHP fatal wrapped in HTTP 200. Mapping and format mistakes
            // show up in the first rows; scanning the rest only delays the
            // answer the operator is waiting for.
            if (null !== $maxRows && $totalRows >= $maxRows) {
                $truncated = true;
                break;
            }

            ++$totalRows;
            $rowErrors = $this->validateRow(
                rowNumber: $rowNumber,
                cells: $cells,
                columnMapping: $columnMapping,
                attributesByCode: $attributesByCode,
                tenant: $tenant,
                skuSeenInFile: $skuSeenInFile,
                identityAttributeCode: $identityAttributeCode,
            );

            $blockingErrors = array_values(array_filter(
                $rowErrors,
                static fn (ValidationError $error): bool => $error->isRowBlocking(),
            ));
            // IMP2-1.9 — mirror the run loop: a blocking Error counts as a row
            // error; a Warning-level DuplicateSkuInFile is a skip (D1) — neither
            // imported nor an error; everything else previews as a clean import.
            $duplicateInFile = [] !== array_filter(
                $rowErrors,
                static fn (ValidationError $error): bool => ImportErrorType::DuplicateSkuInFile === $error->errorType,
            );
            if ([] !== $blockingErrors) {
                ++$errorCount;
            } elseif (!$duplicateInFile) {
                ++$successCount;
            }
            // Surface every finding in the report — including non-blocking
            // warnings (e.g. CategoryNotFound) — so the operator sees
            // the assignment gap even when the row imported cleanly.
            foreach ($rowErrors as $error) {
                if (\count($errors) < self::SAMPLE_LIMIT) {
                    $errors[] = $error;
                }
            }
        }

        return new ValidationResult(
            totalRows: $totalRows,
            successCount: $successCount,
            errorCount: $errorCount,
            errors: $errors,
            truncated: $truncated,
        );
    }

    /**
     * Public wrapper used by the async run handler — same per-row checks
     * the dry-run service performs, but the caller drives iteration and
     * decides what to do with the findings.
     *
     * @param array<string, string|null> $cells                 column_header → value
     * @param array<string, string>      $columnMapping
     * @param array<string, Attribute>   $attributesByCode
     * @param array<string, int>         $skuSeenInFile         mutable; tracks duplicates within the file
     * @param ?string                    $identityAttributeCode column that must carry the row's
     *                                                          identity, or null when the target
     *                                                          models none ({@see self::identityAttributeCode})
     *
     * @return list<ValidationError>
     */
    public function validateRow(
        int $rowNumber,
        array $cells,
        array $columnMapping,
        array $attributesByCode,
        Tenant $tenant,
        array &$skuSeenInFile,
        ?string $identityAttributeCode = self::SKU_ATTRIBUTE_CODE,
    ): array {
        $errors = [];

        // Each entry is one mapped cell with the locale parsed from its
        // dotted header (`name.pl` → locale `pl`). Several localised
        // columns can target the same attribute, so we keep a list rather
        // than a flat code → value map (which the round-trip export would
        // otherwise collapse — #1130).
        /** @var list<array{code: string, locale: ?string, value: ?string, header: string}> $cellValues */
        $cellValues = [];
        // Tracks whether an attribute has a non-empty value in ANY locale
        // column — drives the required-attribute check.
        $presentByAttribute = [];
        $categoryCellValue = null;
        $categoryColumnName = null;
        foreach ($columnMapping as $columnHeader => $attributeCode) {
            // A column the operator skipped (or left unmapped) is not imported,
            // so its header grammar is irrelevant. Supplier files routinely carry
            // columns whose names contain a dot (e.g. "Imp.CodeNr") — without this
            // guard they are misread as an `attribute.locale` suffix and rejected.
            if (ReservedMappingTarget::SKIP === $attributeCode || '' === $attributeCode) {
                continue;
            }

            $parsed = $this->columnGrammar->parse($columnHeader, $tenant);
            if (null !== $parsed->unknownSuffix) {
                $errors[] = new ValidationError(
                    rowNumber: $rowNumber,
                    sku: null,
                    errorType: ImportErrorType::InvalidValue,
                    level: ImportLogLevel::Error,
                    message: \sprintf('Column "%s": suffix "%s" is neither an active locale nor a channel code.', $columnHeader, $parsed->unknownSuffix),
                    columnName: $columnHeader,
                );

                continue;
            }

            // System / read-only export columns (timestamps, status,
            // completeness, …) never carry an Attribute value — skip them
            // so re-importing an export does not flag them.
            if (SystemColumn::isSystem($columnHeader)) {
                continue;
            }
            // IMP2-1.8 — parent_sku is wired in the two-pass relation step
            // (existence/cycle checked there, after all objects are written);
            // variant_axes is parsed + applied by the handler.
            if (ReservedMappingTarget::PARENT_SKU === $attributeCode
                || ReservedMappingTarget::VARIANT_AXES === $attributeCode) {
                continue;
            }
            if (ReservedMappingTarget::isCategory($attributeCode)) {
                // IMP2-1.7: capture the raw (pipe-separated) category cell;
                // per-code resolution + CategoryNotFound warnings run after
                // the loop.
                $cell = $cells[$columnHeader] ?? null;
                if (null !== $cell && '' !== $cell && null === $categoryCellValue) {
                    $categoryCellValue = $cell;
                    $categoryColumnName = $columnHeader;
                }
                continue;
            }
            if (ReservedMappingTarget::STATUS === $attributeCode) {
                // IMP2-1.7: status must be one of the exact export literals;
                // empty = untouched (D2). Bad value blocks the row.
                $cell = $cells[$columnHeader] ?? null;
                if (null !== $cell && '' !== trim($cell) && !\in_array(strtolower(trim($cell)), ['draft', 'published', 'archived'], true)) {
                    $errors[] = new ValidationError(
                        rowNumber: $rowNumber,
                        sku: null,
                        errorType: ImportErrorType::InvalidValue,
                        level: ImportLogLevel::Error,
                        message: \sprintf('Status "%s" is not one of: draft, published, archived.', trim($cell)),
                        columnName: $columnHeader,
                        columnValue: $cell,
                    );
                }
                continue;
            }
            if (ReservedMappingTarget::ENABLED === $attributeCode) {
                // IMP2-1.7: enabled accepts true|false|1|0; empty = untouched.
                $cell = $cells[$columnHeader] ?? null;
                if (null !== $cell && '' !== trim($cell) && !\in_array(strtolower(trim($cell)), ['true', 'false', '1', '0'], true)) {
                    $errors[] = new ValidationError(
                        rowNumber: $rowNumber,
                        sku: null,
                        errorType: ImportErrorType::InvalidValue,
                        level: ImportLogLevel::Error,
                        message: \sprintf('Enabled "%s" must be one of: true, false, 1, 0.', trim($cell)),
                        columnName: $columnHeader,
                        columnValue: $cell,
                    );
                }
                continue;
            }
            $cell = $cells[$columnHeader] ?? null;
            $cellValues[] = [
                'code' => $attributeCode,
                'locale' => $parsed->locale,
                'value' => $cell,
                'header' => $columnHeader,
            ];
            if (null !== $cell && '' !== $cell) {
                $presentByAttribute[$attributeCode] = true;
            }
        }

        $sku = $this->firstNonEmptyValueFor(self::SKU_ATTRIBUTE_CODE, $cellValues);

        // IMP2-1.4/1.5 (#1466/#1467, ADR-0019): required follows
        // Attribute::isRequired instead of a hardcoded sku+name pair —
        // custom ObjectTypes without a "name" attribute import fine, and
        // modelling-defined required attributes are enforced.
        //
        // #2943 — the identity column is required only when the target models
        // one. It used to be a hardcoded `sku`, which made every row of a
        // custom ObjectType fail on a column that type does not have.
        $requiredCodes = null !== $identityAttributeCode ? [$identityAttributeCode] : [];
        foreach ($attributesByCode as $attributeCode => $attribute) {
            if ($attribute->isRequired() && AttributeType::Boolean !== $attribute->getType()) {
                $requiredCodes[] = $attributeCode;
            }
        }

        foreach (array_unique($requiredCodes) as $requiredCode) {
            // A localised required attribute (`name.pl`/`name.en`) is
            // satisfied when any one locale carries a value.
            if (!isset($presentByAttribute[$requiredCode])) {
                $errors[] = new ValidationError(
                    rowNumber: $rowNumber,
                    sku: $sku,
                    errorType: ImportErrorType::MissingRequired,
                    level: ImportLogLevel::Error,
                    message: \sprintf('Missing required attribute "%s".', $requiredCode),
                    columnName: $requiredCode,
                    columnValue: null,
                );
            }
        }

        if (null !== $sku && '' !== $sku) {
            if (isset($skuSeenInFile[$sku])) {
                $errors[] = new ValidationError(
                    rowNumber: $rowNumber,
                    sku: $sku,
                    errorType: ImportErrorType::DuplicateSkuInFile,
                    level: ImportLogLevel::Warning,
                    message: \sprintf('SKU "%s" already appears in the file at row %d.', $sku, $skuSeenInFile[$sku]),
                    columnName: 'sku',
                    columnValue: $sku,
                );
            } else {
                $skuSeenInFile[$sku] = $rowNumber;
                // IMP2-1.3 (#1465): the in-DB duplicate decision moved to the run
                // loop (ObjectResolver + ImportMode) — CREATE skips, UPDATE
                // requires, UPSERT branches. Dry-run buckets arrive in #1492.
            }
        }

        foreach ($cellValues as $cellValue) {
            $value = $cellValue['value'];
            if (null === $value || '' === $value) {
                continue;
            }
            $attribute = $attributesByCode[$cellValue['code']] ?? null;
            if (!$attribute instanceof Attribute) {
                continue;
            }
            $typeError = $this->validateScalarType($attribute, $value);
            if (null !== $typeError) {
                $errors[] = new ValidationError(
                    rowNumber: $rowNumber,
                    sku: $sku,
                    errorType: ImportErrorType::InvalidType,
                    level: ImportLogLevel::Error,
                    message: $typeError,
                    columnName: $cellValue['header'],
                    columnValue: $value,
                );
            }
        }

        if (null !== $categoryCellValue) {
            // IMP2-1.7: validate every code in the multi-value list (pipe or
            // newline, #1719). Each missing code warns separately; the row
            // still imports with whichever codes resolved (writer skips rest).
            $codes = MultiValueSplitter::split($categoryCellValue);
            foreach ($codes as $code) {
                if (null === $this->catalogObjects->findByCode($code, ObjectKind::Category, $tenant)) {
                    $errors[] = new ValidationError(
                        rowNumber: $rowNumber,
                        sku: $sku,
                        errorType: ImportErrorType::CategoryNotFound,
                        level: ImportLogLevel::Warning,
                        message: \sprintf('Category "%s" was not found — skipped for this product.', $code),
                        columnName: $categoryColumnName,
                        columnValue: $code,
                    );
                }
            }
        }

        return $errors;
    }

    /**
     * Resolves attribute_code → Attribute lookups once for the whole
     * import — IMP-04 reuses this to feed the persistence service.
     *
     * @param array<string, string> $columnMapping
     *
     * @return array<string, Attribute>
     */
    public function loadAttributesByCode(Tenant $tenant, array $columnMapping): array
    {
        $codes = [];
        foreach ($columnMapping as $attributeCode) {
            if ('skip' !== $attributeCode && '' !== $attributeCode) {
                $codes[$attributeCode] = true;
            }
        }
        if ([] === $codes) {
            return [];
        }

        $attributes = [];
        foreach (array_keys($codes) as $code) {
            $attribute = $this->attributes->findByCode($code, $tenant);
            if ($attribute instanceof Attribute) {
                $attributes[$attribute->getCode()] = $attribute;
            }
        }

        return $attributes;
    }

    private function validateScalarType(Attribute $attribute, string $value): ?string
    {
        return match ($attribute->getType()) {
            AttributeType::Number => is_numeric(str_replace(',', '.', $value))
                ? null
                : \sprintf('"%s" is not a valid number for "%s".', $value, $attribute->getCode()),
            // Price + metric round-trip as `<number> <currency|unit>`
            // (e.g. "20.99 EUR", "0.3 g") — accept the composite form the
            // exporter emits, not just a bare number.
            AttributeType::Price, AttributeType::Metric => $this->compositeValueParser->isNumericOrComposite($value)
                ? null
                : \sprintf('"%s" is not a valid number for "%s".', $value, $attribute->getCode()),
            AttributeType::Date => false === strtotime($value)
                ? \sprintf('"%s" is not a valid date for "%s".', $value, $attribute->getCode())
                : null,
            AttributeType::Boolean => \in_array(strtolower($value), ['0', '1', 'true', 'false', 'yes', 'no', 'tak', 'nie'], true)
                ? null
                : \sprintf('"%s" is not a valid boolean for "%s".', $value, $attribute->getCode()),
            default => null,
        };
    }

    /**
     * First non-empty cell value targeting the given attribute code,
     * across every (locale) column that maps to it.
     *
     * @param list<array{code: string, locale: ?string, value: ?string, header: string}> $cellValues
     */
    private function firstNonEmptyValueFor(string $attributeCode, array $cellValues): ?string
    {
        foreach ($cellValues as $cellValue) {
            if ($cellValue['code'] !== $attributeCode) {
                continue;
            }
            $value = $cellValue['value'];
            if (null !== $value && '' !== $value) {
                return $value;
            }
        }

        return null;
    }
}
