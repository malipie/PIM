<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Domain\ValueObject\ResolvedImportValue;
use App\Import\Domain\ValueObject\ValidationError;

/**
 * Immutable hand-off between chunk preparation and the handler's write phase.
 *
 * @template TExisting of object
 */
final readonly class ImportChunkPreparation
{
    /**
     * @param list<array{rowNumber: int, cells: array<string, string|null>, sku: ?string, errors: list<ValidationError>, rowOk: bool, resolvedValues: list<ResolvedImportValue>, matchKey: string, duplicateInFile: bool}> $rows
     * @param array<string, TExisting>                                                                                                                                                                                     $existingByKey
     * @param array<string, TExisting>                                                                                                                                                                                     $categoryByCode
     * @param array<string, true>                                                                                                                                                                                          $existingAssetIds
     */
    public function __construct(
        public array $rows,
        public array $existingByKey,
        public array $categoryByCode,
        public array $existingAssetIds,
    ) {
    }
}
