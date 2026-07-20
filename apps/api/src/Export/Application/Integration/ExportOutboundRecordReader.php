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
use App\Channel\Contracts\ChannelResolverInterface;
use App\Channel\Contracts\LocaleCodeResolverInterface;
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
 * Iterates the ObjectType's objects and yields, per requested attribute code,
 * the best-matching value for the binding's channel/locale scope (#2667):
 * rank = localeMatch*2 + channelMatch, so the locale dominates and the channel
 * is the tie-breaker — parity with the ObjectValueLocaleOverlay — with the
 * global (locale/channel-null) value as the rank-0 fallback. A null scope
 * reads global values only (pre-#2667 behavior). Locale fallback chains
 * (LocaleFallbackResolver) are deliberately not walked here — MVP gap; a
 * scoped locale either matches its own rows or falls back straight to global.
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
        private ChannelResolverInterface $channels,
        private LocaleCodeResolverInterface $localeCodes,
    ) {
    }

    public function read(Uuid $objectTypeId, array $codes, ?array $filter = null, ?string $channel = null, ?string $locale = null): iterable
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            return;
        }

        $objectType = $this->objectTypes->findById($objectTypeId);
        if (null === $objectType) {
            return;
        }

        // #2667 — a stale channel code (channel deleted after binding config)
        // must fail the run loudly; silently falling back to global values
        // would push e.g. global prices to a channel-scoped remote. Mirrors
        // the malformed-FilterDsl behavior below (fail the run, not the save).
        $channelId = null;
        if (null !== $channel && '' !== $channel) {
            $channelId = $this->channels->resolveId($channel, $tenant);
            if (null === $channelId) {
                throw new RuntimeException(\sprintf('Source channel "%s" on the sync binding does not resolve for the tenant.', $channel));
            }
        }
        $shortLocale = null !== $locale && '' !== $locale ? $this->localeCodes->toShort($locale) : null;

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
            foreach ($this->scopedValues($object, $wanted, $channelId, $shortLocale) as $code => $objectValue) {
                $values[$code] = $this->serializer->serialize($objectValue);
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
     * The best value per wanted attribute code for the requested scope: rows
     * whose non-null locale/channel does not match the scope are skipped, the
     * rest rank `localeMatch*2 + channelMatch` (locale-first, channel as
     * tie-breaker — ObjectValueLocaleOverlay parity) and the highest rank wins;
     * the global row is the rank-0 base. With a null scope only global rows
     * survive the skip, preserving the pre-#2667 behavior.
     *
     * @param array<string, true> $wanted
     *
     * @return array<string, ObjectValue> attribute code => best-ranked value
     */
    private function scopedValues(CatalogObject $object, array $wanted, ?Uuid $channelId, ?string $shortLocale): array
    {
        $best = [];
        $bestRank = [];

        $values = $this->em->getRepository(ObjectValue::class)->findBy(['object' => $object]);
        foreach ($values as $value) {
            $code = $value->getAttribute()->getCode();
            if (!isset($wanted[$code])) {
                continue;
            }

            $rowLocale = $value->getLocale();
            if (null !== $rowLocale && $rowLocale !== $shortLocale) {
                continue;
            }
            $rowChannel = $value->getChannelId();
            if (null !== $rowChannel && (null === $channelId || !$rowChannel->equals($channelId))) {
                continue;
            }

            $rank = (null !== $rowLocale ? 2 : 0) + (null !== $rowChannel ? 1 : 0);
            if (!isset($bestRank[$code]) || $rank > $bestRank[$code]) {
                $best[$code] = $value;
                $bestRank[$code] = $rank;
            }
        }

        return $best;
    }
}
