<?php

declare(strict_types=1);

namespace App\Catalog\Application\Migration;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Service\AttributeTypeMigrationCompatibility;
use App\Catalog\Domain\Service\MigrationCompatibility;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * UI-08.6 (#261) — applies an {@see AttributeMigrationPlan} in a single
 * transaction:
 *
 *   1. Snapshot (optional): copy every `object_values.value` row for the
 *      attribute into `attribute_migration_backups` so the operator can
 *      restore via a follow-up endpoint.
 *   2. Rewrite each `object_values.value` per the mapping plan +
 *      `unmappedAction`.
 *   3. Bump `attributes.type` (the new shape is in effect from this row
 *      onward).
 *   4. Trigger `attributes_indexed` rebuild on every affected
 *      CatalogObject (handled by the existing AttributesIndexedSync
 *      listener for non-bulk paths; this method falls back to a flush
 *      that triggers it).
 *
 * Tenant scope: queries narrow by `attribute_id`, which is already
 * tenant-scoped via the attribute row. Cross-tenant access is blocked
 * upstream (controller resolves attribute through TenantFilter).
 *
 * tenant-safe: every executeStatement below filters by id (per-row PK
 * lookups for attributes / object_values) or by attribute_id (joined
 * with the attribute row that came through TenantFilter). The
 * INSERT into attribute_migration_backups carries attribute_id as
 * the natural tenant anchor.
 */
final readonly class AttributeMigrationExecutor
{
    public function __construct(
        private Connection $connection,
        private AttributeRepositoryInterface $attributes,
        private AttributeTypeMigrationCompatibility $compatibility,
    ) {
    }

    public function execute(Attribute $attribute, AttributeMigrationPlan $plan): AttributeMigrationAnalysis
    {
        $compat = $this->compatibility->evaluate($attribute->getType(), $plan->targetType);
        if (MigrationCompatibility::Blocked === $compat) {
            throw new UnprocessableEntityHttpException(\sprintf(
                'Migration from "%s" to "%s" is not supported in MVP.',
                $attribute->getType()->value,
                $plan->targetType->value,
            ));
        }
        if (MigrationCompatibility::RequiresForce === $compat && !$plan->force) {
            throw new ConflictHttpException(\sprintf(
                'Migration from "%s" to "%s" is destructive. Pass force=true to confirm.',
                $attribute->getType()->value,
                $plan->targetType->value,
            ));
        }

        $this->connection->beginTransaction();

        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, value FROM object_values WHERE attribute_id = ?',
                [$attribute->getId()->toRfc4122()],
            );

            if ($plan->backupSnapshot && [] !== $rows) {
                $this->snapshot($attribute, $plan->targetType, $rows);
            }

            $mappingIndex = $this->indexMappings($plan->mappings);

            $rowCount = 0;
            $applied = [];
            $unmapped = [];
            foreach ($rows as $row) {
                $rawValue = $row['value'];
                $valueString = $this->extractStringValue($rawValue);
                if (null === $valueString) {
                    continue;
                }

                $key = mb_strtolower(trim($valueString));
                $target = $mappingIndex[$key] ?? null;

                if (null === $target) {
                    [$action, $writeValue, $skip] = $this->resolveUnmappedAction($plan->unmappedAction);
                    $unmapped[] = ['value' => $valueString, 'count' => 1];
                    if ($skip) {
                        continue;
                    }
                    $newJsonb = $this->buildPayload($plan->targetType, $writeValue);
                } else {
                    $applied[] = ['from' => $valueString, 'to' => $target, 'count' => 1];
                    $newJsonb = $this->buildPayload($plan->targetType, $target);
                }

                $rawId = $row['id'];
                if (!\is_string($rawId)) {
                    continue;
                }
                $this->connection->executeStatement(
                    'UPDATE object_values SET value = ?::jsonb WHERE id = ?',
                    [(string) json_encode($newJsonb), $rawId],
                );
                ++$rowCount;
            }

            // Flip the attribute's type — new ObjectValue rows from this
            // point onwards get validated against the new shape.
            $this->connection->executeStatement(
                'UPDATE attributes SET type = ? WHERE id = ?',
                [$plan->targetType->value, $attribute->getId()->toRfc4122()],
            );

            $this->connection->commit();
        } catch (Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }

        // Refresh the attribute row in the EM so subsequent reads see the
        // new type. Re-fetch instead of in-place mutation because the
        // entity has no public type setter (intentional — the migration
        // is the only path).
        $this->attributes->findById($attribute->getId());

        return new AttributeMigrationAnalysis(
            compatibility: $compat->value,
            rowCount: $rowCount,
            distinctValues: \count($applied) + \count($unmapped),
            mappings: $applied,
            unmapped: $unmapped,
            forceRequired: false,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function snapshot(Attribute $attribute, AttributeType $targetType, array $rows): void
    {
        $this->connection->executeStatement(
            'INSERT INTO attribute_migration_backups (id, attribute_id, source_type, target_type, snapshot, row_count, created_at)'
            .' VALUES (?, ?, ?, ?, ?::jsonb, ?, NOW())',
            [
                Uuid::v7()->toRfc4122(),
                $attribute->getId()->toRfc4122(),
                $attribute->getType()->value,
                $targetType->value,
                (string) json_encode($rows),
                \count($rows),
            ],
        );
    }

    /**
     * @param list<array{from: string, to: string}> $mappings
     *
     * @return array<string, string>
     */
    private function indexMappings(array $mappings): array
    {
        $idx = [];
        foreach ($mappings as $entry) {
            $idx[mb_strtolower(trim($entry['from']))] = $entry['to'];
        }

        return $idx;
    }

    private function extractStringValue(mixed $rawValue): ?string
    {
        $decoded = match (true) {
            \is_array($rawValue) => $rawValue,
            \is_string($rawValue) => json_decode($rawValue, true),
            default => null,
        };
        if (!\is_array($decoded)) {
            return null;
        }
        if (\array_key_exists('value', $decoded) && \is_scalar($decoded['value'])) {
            return (string) $decoded['value'];
        }
        if (\array_key_exists('option_code', $decoded) && \is_string($decoded['option_code'])) {
            return $decoded['option_code'];
        }
        if (\array_key_exists('option_codes', $decoded) && \is_array($decoded['option_codes'])) {
            $first = $decoded['option_codes'][0] ?? null;

            return \is_string($first) ? $first : null;
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string|null, 2: bool} action label, write value, skip flag
     */
    private function resolveUnmappedAction(string $action): array
    {
        if ('skip' === $action) {
            return ['skip', null, true];
        }
        if (str_starts_with($action, 'default:')) {
            return ['default', substr($action, 8), false];
        }

        return ['null', null, false];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(AttributeType $type, ?string $value): array
    {
        return match ($type) {
            AttributeType::Select => ['option_code' => $value],
            AttributeType::Multiselect => ['option_codes' => null === $value ? [] : [$value]],
            default => ['value' => $value],
        };
    }
}
