<?php

declare(strict_types=1);

namespace App\Export\Application\Scope;

use App\Catalog\Application\Filter\FilterDslResolver;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Export\Domain\Entity\ExportSession;
use App\Export\Domain\Enum\ExportEntityType;
use App\Export\Domain\Enum\ExportTargetScope;
use App\Shared\Domain\Tenant;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * #2987 — the single source of truth for the rows behind an export counter.
 *
 * Preflight and the file runner both call this resolver, so Selected / Filter /
 * All and the variants flag can no longer drift between UI count and output.
 * The returned id plan is tenant + ObjectType scoped before variant fan-out.
 */
final readonly class ExportScopeResolver
{
    public function __construct(
        private CatalogObjectRepositoryInterface $objects,
        private ObjectTypeRepositoryInterface $objectTypes,
        private FilterDslResolver $filterDsl,
        private Connection $connection,
    ) {
    }

    public function resolve(ExportSession $session): ResolvedCatalogScope
    {
        $tenant = $session->getTenant();
        if (!$tenant instanceof Tenant) {
            throw new LogicException('Export scope resolution requires an assigned tenant.');
        }

        $objectType = $this->resolveObjectType($session, $tenant);
        $baseIds = match ($session->getTargetScope()) {
            ExportTargetScope::Selected => $this->selectedBaseIds($session, $tenant, $objectType),
            ExportTargetScope::All => $this->objects->findRootObjectIds($objectType, $tenant),
            ExportTargetScope::Filter => $this->filterBaseIds($session, $tenant, $objectType),
        };

        sort($baseIds);
        if ([] === $baseIds) {
            return new ResolvedCatalogScope($objectType->getId(), []);
        }

        // All already starts with roots. Selected/filter may contain directly
        // selected variants; they remain in a flat export, while tree mode
        // (include_variants=false) deliberately keeps masters only.
        $rootIds = ExportTargetScope::All === $session->getTargetScope()
            ? $baseIds
            : $this->objects->filterRootObjectIds($baseIds, $tenant);

        if (!$session->includesVariants()) {
            $rootSet = array_fill_keys($rootIds, true);

            return new ResolvedCatalogScope(
                $objectType->getId(),
                array_values(array_filter($baseIds, static fn (string $id): bool => isset($rootSet[$id]))),
            );
        }

        $childIdsByParent = $this->objects->findChildIdsByParentIds($rootIds, $tenant);
        $plan = [];
        $seen = [];
        foreach ($baseIds as $id) {
            if (isset($seen[$id])) {
                continue;
            }
            $plan[] = $id;
            $seen[$id] = true;

            foreach ($childIdsByParent[$id] ?? [] as $childId) {
                if (!isset($seen[$childId])) {
                    $plan[] = $childId;
                    $seen[$childId] = true;
                }
            }
        }

        return new ResolvedCatalogScope($objectType->getId(), $plan);
    }

    /** @return list<string> */
    private function selectedBaseIds(ExportSession $session, Tenant $tenant, ObjectType $objectType): array
    {
        $requested = $session->getSelectedObjectIds();
        if (null === $requested || [] === $requested) {
            return [];
        }

        $normalised = [];
        foreach ($requested as $id) {
            $normalised[$id] = true;
        }

        // Do not trust ids supplied by a browser/profile. Preflight and run
        // discard stale, foreign-tenant and wrong-ObjectType ids identically.
        $rows = $this->connection->fetchFirstColumn(
            'SELECT id FROM objects WHERE tenant_id = :tenant AND object_type_id = :type AND id IN (:ids)',
            [
                'tenant' => $tenant->getId()->toRfc4122(),
                'type' => $objectType->getId()->toRfc4122(),
                'ids' => array_keys($normalised),
            ],
            ['ids' => ArrayParameterType::STRING],
        );

        return array_values(array_filter($rows, \is_string(...)));
    }

    /** @return list<string> */
    private function filterBaseIds(ExportSession $session, Tenant $tenant, ObjectType $objectType): array
    {
        $dsl = $session->getFilterSnapshot();
        if (null === $dsl || [] === $dsl) {
            return [];
        }

        $this->filterDsl->validate($dsl);
        $whereClause = $this->filterDsl->toCountSql($dsl);
        if (null === $whereClause) {
            throw new RuntimeException('Invalid filter DSL in export session snapshot.');
        }

        try {
            $rows = $this->connection->fetchFirstColumn(
                'SELECT co.id FROM objects co WHERE co.tenant_id = :tenant AND co.object_type_id = :type AND ('.$whereClause.')',
                [
                    'tenant' => $tenant->getId()->toRfc4122(),
                    'type' => $objectType->getId()->toRfc4122(),
                ],
            );
        } catch (Throwable $error) {
            throw new RuntimeException('Filter scope SQL execution failed: '.$error->getMessage(), previous: $error);
        }

        return array_values(array_filter($rows, \is_string(...)));
    }

    private function resolveObjectType(ExportSession $session, Tenant $tenant): ObjectType
    {
        return match ($session->getEntityType()) {
            ExportEntityType::Product => $this->objectTypes->findBuiltInByKind(ObjectKind::Product, $tenant)
                ?? throw new LogicException('Built-in Product ObjectType is not seeded for this tenant.'),
            ExportEntityType::CustomModule => $this->resolveCustomObjectType($session),
            default => throw new LogicException(sprintf(
                'Export entity_type "%s" is not backed by catalog objects.',
                $session->getEntityType()->value,
            )),
        };
    }

    private function resolveCustomObjectType(ExportSession $session): ObjectType
    {
        $id = $session->getObjectTypeId();
        if (null === $id) {
            throw new LogicException('custom_module export session is missing object_type_id.');
        }
        $objectType = $this->objectTypes->findById($id);
        if (null === $objectType) {
            throw new LogicException(sprintf('ObjectType "%s" was not found.', $id->toRfc4122()));
        }

        return $objectType;
    }
}
