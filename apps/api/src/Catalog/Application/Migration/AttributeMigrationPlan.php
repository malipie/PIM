<?php

declare(strict_types=1);

namespace App\Catalog\Application\Migration;

use App\Catalog\Contracts\AttributeType;

/**
 * Input contract for `AttributeMigrationPlanner` + `AttributeMigrationExecutor`.
 *
 * Mapping entries: `from` is the input string (text / option_code), `to` is
 * the target representation (option_code for select/multiselect, raw value
 * for text/number/boolean/date).
 *
 * Unmapped action drives what happens to values not covered by the plan:
 *  - `null`: write `{value: null}` (or option_code: null).
 *  - `skip`: leave the row untouched (still counted in unmapped).
 *  - `default:<value>`: substitute with `<value>` literal.
 *
 * @phpstan-type MappingEntry array{from: string, to: string}
 */
final readonly class AttributeMigrationPlan
{
    /**
     * @param list<MappingEntry> $mappings
     */
    public function __construct(
        public AttributeType $targetType,
        public array $mappings,
        public string $unmappedAction,
        public bool $force,
        public bool $backupSnapshot,
    ) {
    }
}
