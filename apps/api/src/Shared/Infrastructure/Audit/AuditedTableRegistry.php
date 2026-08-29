<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Audit;

use DH\Auditor\Provider\Doctrine\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Mapping\MappingException;

/**
 * #3045 — the audit tables the running configuration actually requires,
 * derived from `dh_auditor.yaml` instead of a hand-kept list.
 *
 * The whole incident started as list drift: three entities were added to the
 * auditor config and nobody created their tables. A schema check keyed on its
 * own hardcoded copy of that list cannot catch it — it only knows about the
 * tables somebody remembered to write down twice. Deriving the expectation
 * from the same source the auditor itself reads closes the loop.
 */
final readonly class AuditedTableRegistry
{
    public function __construct(
        private Configuration $auditorConfiguration,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Audit table name per audited entity FQCN, sorted by table name.
     *
     * @return array<class-string, string>
     */
    public function expectedTables(): array
    {
        $prefix = $this->auditorConfiguration->getTablePrefix();
        $suffix = $this->auditorConfiguration->getTableSuffix();

        $tables = [];
        foreach (array_keys($this->auditorConfiguration->getEntities()) as $entity) {
            if (!\is_string($entity) || !class_exists($entity)) {
                continue;
            }
            try {
                $metadata = $this->entityManager->getClassMetadata($entity);
            } catch (MappingException) {
                // Configured but not mapped — the auditor would never write for
                // it either, so it needs no table.
                continue;
            }
            /* @var class-string $entity */
            $tables[$entity] = $prefix.$metadata->getTableName().$suffix;
        }
        asort($tables);

        return $tables;
    }

    /**
     * @return list<string>
     */
    public function expectedTableNames(): array
    {
        return array_values(array_unique(array_values($this->expectedTables())));
    }
}
