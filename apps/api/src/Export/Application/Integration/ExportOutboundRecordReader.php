<?php

declare(strict_types=1);

namespace App\Export\Application\Integration;

use App\Catalog\Application\Filter\FilterDslResolver;
use App\Catalog\Contracts\Integration\OutboundRecord;
use App\Catalog\Contracts\Integration\OutboundRecordReader;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Export\Application\Builder\ValueSerializer;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Export-side implementation of the outbound-sync read seam (APIC-P3-06).
 *
 * Reuses the export {@see ValueSerializer} (the cell serializer) instead of a
 * bespoke one, so the outbound payload matches what a file export would emit.
 * Iterates the ObjectType's objects and yields each global (locale/channel-null)
 * value for the requested attribute codes, serialised to a scalar string.
 *
 * MVP reads the full object set per run; keyset paging for 50k+ catalogs is a
 * follow-up (the inbound runner has the same bounded-scope note).
 */
final readonly class ExportOutboundRecordReader implements OutboundRecordReader
{
    public function __construct(
        private ObjectTypeRepositoryInterface $objectTypes,
        private CatalogObjectRepositoryInterface $objects,
        private ValueSerializer $serializer,
        private TenantContext $tenantContext,
        private EntityManagerInterface $em,
        private FilterDslResolver $filterDsl,
    ) {
    }

    public function read(Uuid $objectTypeId, array $codes, ?array $filter = null): iterable
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            return;
        }

        $objectType = $this->objectTypes->findById($objectTypeId);
        if (null === $objectType) {
            return;
        }

        // #2549 — outbound scope: null = every object; a keyed set = only the
        // ids matching the FilterDsl (skip everything else BEFORE loading its
        // values, so the filtered-out set costs no per-object value query).
        $allowedIds = $this->resolveFilterIds($filter, $tenant, $objectType);

        $wanted = array_fill_keys($codes, true);

        foreach ($this->objects->findByObjectType($objectType, $tenant) as $object) {
            if (null !== $allowedIds && !isset($allowedIds[$object->getId()->toRfc4122()])) {
                continue;
            }

            $values = [];
            foreach ($this->globalValues($object) as $code => $objectValue) {
                if (isset($wanted[$code])) {
                    $values[$code] = $this->serializer->serialize($objectValue);
                }
            }

            yield new OutboundRecord($object->getId()->toRfc4122(), $values);
        }
    }

    /**
     * Compile the outbound FilterDsl into tenant-scoped SQL and return the
     * matching object ids as a keyed set (id => true) for O(1) lookup, or null
     * when no filter is set. Mirrors {@see \App\Export\Application\Sync\SyncExportRunner::resolveFilterIds()}.
     *
     * @param array<string, mixed>|null $filter
     *
     * @return array<string, true>|null
     */
    private function resolveFilterIds(?array $filter, Tenant $tenant, ObjectType $objectType): ?array
    {
        if (null === $filter || [] === $filter) {
            return null;
        }

        $whereClause = $this->filterDsl->toCountSql($filter);
        if (null === $whereClause) {
            throw new RuntimeException('Invalid outbound filter DSL on the sync binding.');
        }

        $sql = 'SELECT co.id FROM objects co '
            .'WHERE co.tenant_id = :tenant AND co.object_type_id = :otid AND ('.$whereClause.')';

        try {
            $rows = $this->em->getConnection()->fetchFirstColumn($sql, [
                'tenant' => $tenant->getId()->toRfc4122(),
                'otid' => $objectType->getId()->toRfc4122(),
            ]);
        } catch (Throwable $error) {
            throw new RuntimeException('Outbound filter scope SQL failed: '.$error->getMessage(), previous: $error);
        }

        $ids = [];
        foreach ($rows as $id) {
            if (\is_string($id)) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /**
     * @return iterable<string, ObjectValue> attribute code => the global value
     *                                       (locale/channel-null) only
     */
    private function globalValues(CatalogObject $object): iterable
    {
        $values = $this->em->getRepository(ObjectValue::class)->findBy(['object' => $object]);
        foreach ($values as $value) {
            if (null === $value->getLocale() && null === $value->getChannelId()) {
                yield $value->getAttribute()->getCode() => $value;
            }
        }
    }
}
