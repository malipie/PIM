<?php

declare(strict_types=1);

namespace App\Export\Application\Catalog;

use App\Catalog\Application\Filter\FilterDslResolver;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Export\Application\Builder\ExportBuilder;
use App\Export\Contracts\CatalogProductScope;
use App\Export\Contracts\CatalogProductValues;
use App\Export\Domain\Entity\ExportSession;
use App\Export\Domain\Enum\ExportEntityType;
use App\Export\Domain\Enum\ExportFormat;
use App\Export\Domain\Enum\ExportSource;
use App\Export\Domain\Enum\ExportTargetScope;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Generator;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * Export-core adapter that streams a catalog's product values through the existing
 * {@see ExportBuilder} (ADR-0023 §6.3, CPDF-P2-02). It lives in Export core (not
 * the Catalog sub-area) so it can reuse the catalog id-resolution + value builder
 * the way {@see \App\Export\Application\Sync\SyncExportRunner} does; the catalog
 * generator depends only on the {@see CatalogProductValues} seam.
 *
 * The catalog's scope becomes an in-memory {@see ExportSession} (never persisted);
 * products are paged in CLEAR_INTERVAL keyset chunks with EntityManager::clear()
 * between pages, so a 50k catalog stays memory-flat. Column keys are requested both
 * bare and locale-scoped, then collapsed back to attribute codes for the mapper.
 */
final class ExportBuilderCatalogValues implements CatalogProductValues
{
    private const int CLEAR_INTERVAL = 200;

    public function __construct(
        private readonly ExportBuilder $builder,
        private readonly CatalogObjectRepositoryInterface $objects,
        private readonly ObjectTypeRepositoryInterface $objectTypes,
        private readonly FilterDslResolver $filterDsl,
        private readonly Connection $connection,
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function forScope(CatalogProductScope $scope): iterable
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw new RuntimeException('Catalog generation requires a tenant context.');
        }
        $tenantId = $tenant->getId();

        $objectType = $this->objectTypes->findById($scope->objectTypeId);
        if (null === $objectType) {
            throw new RuntimeException(sprintf('ObjectType "%s" was not found.', $scope->objectTypeId->toRfc4122()));
        }

        $columns = $this->columnKeys($scope);
        $session = $this->session($scope, $columns);
        $session->assignTenant($tenant);

        $ids = null === $scope->filter
            ? $this->objects->findRootObjectIds($objectType, $tenant)
            : $this->filterIds($scope, $tenant, $objectType);

        // CPDF-P2-02 — flat variants (plan §6.6): a master WITH variants is
        // represented by its variants (each becomes its own row, carrying
        // the master's SKU in the `parent_sku` built-in for item_group_id);
        // a master without variants stays a single item. One grouped id query
        // for the whole scope — the sync runner's emit-id-plan pattern.
        $childIdsByParent = $this->objects->findChildIdsByParentIds($ids, $tenant);
        $emitIds = [];
        foreach ($ids as $id) {
            $children = $childIdsByParent[$id] ?? [];
            foreach ([] === $children ? [$id] : $children as $emitId) {
                $emitIds[] = $emitId;
            }
        }

        foreach (array_chunk($emitIds, self::CLEAR_INTERVAL) as $page) {
            $this->reattachTenant($session, $tenantId);
            foreach ($this->builder->build($this->hydrateInOrder($page), $session) as $row) {
                yield $this->toAttributeMap($row, $scope);
            }
            $this->em->clear();
            // clear() detaches the Tenant the caller's TenantContext holds; the
            // catalog generator persists run rows (TenantScoped) after us and
            // TenantAssignmentListener would otherwise assign a detached Tenant →
            // "new entity found through relationship". Rebind a managed instance
            // so downstream persists stay in the identity map.
            $this->rebindTenantContext($tenantId);
        }
    }

    private function rebindTenantContext(Uuid $tenantId): void
    {
        $tenant = $this->em->find(Tenant::class, $tenantId->toRfc4122());
        if ($tenant instanceof Tenant) {
            $this->tenantContext->set($tenant);
        }
    }

    /**
     * @param list<string> $columns
     */
    private function session(CatalogProductScope $scope, array $columns): ExportSession
    {
        return new ExportSession(
            userId: Uuid::v7(),
            source: ExportSource::SavedProfileRun,
            format: ExportFormat::Xml,
            targetScope: null === $scope->filter ? ExportTargetScope::All : ExportTargetScope::Filter,
            selectedColumns: $columns,
            filterSnapshot: $this->stringKeyed($scope->filter),
            locales: null !== $scope->locale ? [$scope->locale] : null,
            channels: null !== $scope->channel ? [$scope->channel] : null,
            includeVariants: true,
            entityType: ExportEntityType::Product,
            objectTypeId: $scope->objectTypeId,
        );
    }

    /**
     * Request each attribute both bare and locale-scoped so the mapper can prefer
     * the localized value and fall back to the global one.
     *
     * @return list<string>
     */
    private function columnKeys(CatalogProductScope $scope): array
    {
        $keys = [];
        foreach ($scope->attributeCodes as $code) {
            $keys[$code] = true;
            if (null !== $scope->locale) {
                $keys[$code.'.'.$scope->locale] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * @param array<string, string> $row
     *
     * @return array<string, string>
     */
    private function toAttributeMap(array $row, CatalogProductScope $scope): array
    {
        $item = [];
        foreach ($scope->attributeCodes as $code) {
            $localized = null !== $scope->locale ? ($row[$code.'.'.$scope->locale] ?? '') : '';
            $item[$code] = '' !== $localized ? $localized : ($row[$code] ?? '');
        }

        return $item;
    }

    /**
     * @param list<string> $idPage
     *
     * @return list<CatalogObject>
     */
    private function hydrateInOrder(array $idPage): array
    {
        $byId = [];
        foreach ($this->objects->findByIds($idPage) as $object) {
            $byId[$object->getId()->toRfc4122()] = $object;
        }

        $ordered = [];
        foreach ($idPage as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    /**
     * The filter snapshot is a JSON object (string-keyed at runtime); narrow it
     * for the ExportSession / FilterDslResolver which type it as array<string,mixed>.
     *
     * @param array<mixed, mixed>|null $filter
     *
     * @return array<string, mixed>|null
     */
    private function stringKeyed(?array $filter): ?array
    {
        if (null === $filter) {
            return null;
        }
        $out = [];
        foreach ($filter as $key => $value) {
            if (\is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function reattachTenant(ExportSession $session, Uuid $tenantId): void
    {
        $tenant = $this->em->find(Tenant::class, $tenantId->toRfc4122());
        if ($tenant instanceof Tenant) {
            $session->rebindTenant($tenant);
        }
    }

    /**
     * @return list<string>
     */
    private function filterIds(CatalogProductScope $scope, Tenant $tenant, ObjectType $objectType): array
    {
        $dsl = $this->stringKeyed($scope->filter);
        if (null === $dsl || [] === $dsl) {
            return [];
        }
        $whereClause = $this->filterDsl->toCountSql($dsl);
        if (null === $whereClause) {
            throw new RuntimeException('Invalid filter DSL in catalog scope.');
        }

        $sql = 'SELECT co.id FROM objects co WHERE co.tenant_id = :tenant AND co.object_type_id = :otid AND ('.$whereClause.')';
        $rows = $this->connection->fetchFirstColumn($sql, [
            'tenant' => $tenant->getId()->toRfc4122(),
            'otid' => $objectType->getId()->toRfc4122(),
        ]);

        $ids = [];
        foreach ($rows as $id) {
            if (\is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
