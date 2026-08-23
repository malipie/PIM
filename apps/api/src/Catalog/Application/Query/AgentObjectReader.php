<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Application\ObjectValueLocaleOverlay;
use App\Catalog\Application\ValueWriteCore;
use App\Catalog\Contracts\Query\AgentObjectReadPort;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectCategory;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectCategoryRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectValueRepositoryInterface;
use App\Channel\Contracts\ChannelResolverInterface;
use App\Channel\Contracts\LocaleFallbackResolverInterface;
use App\Identity\Contracts\Policy\UserScopedPermissionCheckerInterface;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * #2983 — the catalog-side security boundary behind the agent get_object
 * tool. The lookup carries an explicit tenant predicate (RLS remains the
 * backstop), value visibility is evaluated for the initiating run user id,
 * and locale/channel resolution reuses the public read overlay.
 */
final readonly class AgentObjectReader implements AgentObjectReadPort
{
    private const int DEFAULT_ATTRIBUTE_LIMIT = 50;
    private const int TEXT_LIMIT = 2000;

    public function __construct(
        private EntityManagerInterface $em,
        private ObjectValueLocaleOverlay $overlay,
        private AttributeRepositoryInterface $attributes,
        private ObjectValueRepositoryInterface $values,
        private ObjectCategoryRepositoryInterface $categories,
        private UserScopedPermissionCheckerInterface $permissions,
        private ChannelResolverInterface $channels,
        private LocaleFallbackResolverInterface $localeFallback,
    ) {
    }

    public function read(
        Tenant $tenant,
        Uuid $userId,
        ?Uuid $objectId,
        ?string $code,
        ?string $objectTypeCode,
        ?array $attributeCodes,
        ?string $locale,
        ?string $channel,
    ): ?array {
        $object = $this->findObject($tenant, $objectId, $code, $objectTypeCode);
        if (!$object instanceof CatalogObject) {
            return null;
        }

        $scoped = $this->overlay->apply($object, $locale, $channel);
        $indexed = $scoped->getAttributesIndexed();
        $resolvedRows = $this->resolveSourceRows($object, $locale, $channel);
        $requested = null === $attributeCodes ? null : array_fill_keys($attributeCodes, true);

        $visibleDefinitions = [];
        foreach ($this->attributes->findAllByTenant($tenant) as $attribute) {
            if (null !== $requested && !isset($requested[$attribute->getCode()])) {
                continue;
            }
            if (!$this->permissions->canViewAttribute($userId, $attribute->getId())) {
                continue;
            }
            $visibleDefinitions[$attribute->getCode()] = $attribute;
        }

        $values = [];
        $omitted = 0;
        foreach ($visibleDefinitions as $attributeCode => $attribute) {
            $envelope = $indexed[$attributeCode] ?? null;
            if (!\is_array($envelope) || ValueWriteCore::isEmptyEnvelope($envelope)) {
                continue;
            }
            /** @var array<string, mixed> $typedEnvelope */
            $typedEnvelope = $envelope;
            if (null === $attributeCodes && \count($values) >= self::DEFAULT_ATTRIBUTE_LIMIT) {
                ++$omitted;
                continue;
            }

            [$safeEnvelope, $truncated] = $this->truncateEnvelope($typedEnvelope);
            $values[$attributeCode] = [
                'type' => $attribute->getType()->value,
                'label' => $attribute->getLabel(),
                'value' => $safeEnvelope,
                'provenance' => $this->provenance($resolvedRows[$attributeCode] ?? null, $locale, $channel),
                'truncated' => $truncated,
            ];
        }

        return [
            'id' => $object->getId()->toRfc4122(),
            'code' => $object->getCode(),
            'object_type' => [
                'id' => $object->getObjectType()->getId()->toRfc4122(),
                'code' => $object->getObjectType()->getCode(),
                'label' => $object->getObjectType()->getLabel(),
                'kind' => $object->getKind()->value,
            ],
            'status' => $object->getStatus(),
            'completeness' => $object->getCompleteness(),
            'attributes' => $values,
            'attributes_omitted' => $omitted,
            'categories' => array_map(
                static fn (ObjectCategory $assignment): array => [
                    'id' => $assignment->getCategory()->getId()->toRfc4122(),
                    'code' => $assignment->getCategory()->getCode(),
                    'primary' => $assignment->isPrimary(),
                ],
                $this->categories->findByProduct($object),
            ),
            'context' => ['locale' => $locale, 'channel' => $channel],
        ];
    }

    private function findObject(Tenant $tenant, ?Uuid $objectId, ?string $code, ?string $objectTypeCode): ?CatalogObject
    {
        $qb = $this->em->createQueryBuilder()
            ->select('co', 'ot')
            ->from(CatalogObject::class, 'co')
            ->innerJoin('co.objectType', 'ot')
            ->andWhere('co.tenant = :tenant')
            ->setParameter('tenant', $tenant->getId(), 'uuid')
            ->setMaxResults(1);

        if ($objectId instanceof Uuid) {
            $qb->andWhere('co.id = :id')->setParameter('id', $objectId, 'uuid');
        } elseif (null !== $code && '' !== $code && null !== $objectTypeCode && '' !== $objectTypeCode) {
            $qb->andWhere('co.code = :code')
                ->andWhere('ot.code = :type')
                ->setParameter('code', $code)
                ->setParameter('type', $objectTypeCode);
        } else {
            return null;
        }

        /** @var CatalogObject|null $object */
        $object = $qb->getQuery()->getOneOrNullResult();

        return $object;
    }

    /** @return array<string, ObjectValue> attribute code => winning row */
    private function resolveSourceRows(CatalogObject $object, ?string $locale, ?string $channel): array
    {
        $tenant = $object->getTenant();
        if (!$tenant instanceof Tenant) {
            return [];
        }

        $effectiveLocale = null !== $locale && $locale !== $tenant->getPrimaryLocale() ? $locale : null;
        $localeChain = null === $effectiveLocale ? [] : $this->localeFallback->resolve($effectiveLocale, $tenant);
        $localePositions = array_flip($localeChain);
        $maxChainLen = \count($localeChain);
        $channelId = null === $channel ? null : $this->channels->resolveId($channel, $tenant);

        $best = [];
        foreach ($this->values->findByObject($object) as $row) {
            $rowLocale = $row->getLocale();
            if (null !== $rowLocale) {
                $position = $localePositions[$rowLocale] ?? null;
                if (!\is_int($position)) {
                    continue;
                }
                $localeRank = ($maxChainLen - $position) * 2;
            } else {
                $localeRank = 0;
            }

            $rowChannel = $row->getChannelId();
            if (null !== $rowChannel) {
                if (null === $channelId || !$rowChannel->equals($channelId)) {
                    continue;
                }
                $channelRank = 1;
            } else {
                $channelRank = 0;
            }

            $rank = $localeRank + $channelRank;
            $code = $row->getAttribute()->getCode();
            if (!isset($best[$code]) || $rank > $best[$code]['rank']) {
                $best[$code] = ['rank' => $rank, 'row' => $row];
            }
        }

        return array_map(static fn (array $entry): ObjectValue => $entry['row'], $best);
    }

    /** @return array<string, mixed> */
    private function provenance(?ObjectValue $row, ?string $requestedLocale, ?string $requestedChannel): array
    {
        if (!$row instanceof ObjectValue) {
            return ['source' => 'indexed_cache', 'kind' => null, 'meta' => []];
        }

        $source = match (true) {
            null !== $row->getLocale() && null !== $row->getChannelId() => 'locale_channel',
            null !== $row->getLocale() => 'locale',
            null !== $row->getChannelId() => 'channel',
            default => 'global',
        };

        return [
            'source' => $source,
            'kind' => $row->getProvenance()->value,
            'meta' => $row->getProvenanceMeta(),
            'locale' => $row->getLocale(),
            'channel' => null === $row->getChannelId() ? null : $requestedChannel,
            'requested_locale' => $requestedLocale,
            'requested_channel' => $requestedChannel,
        ];
    }

    /** @param array<string, mixed> $envelope
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function truncateEnvelope(array $envelope): array
    {
        $truncated = false;
        $walk = static function (mixed $value) use (&$walk, &$truncated): mixed {
            if (\is_string($value) && mb_strlen($value) > self::TEXT_LIMIT) {
                $truncated = true;

                return mb_substr($value, 0, self::TEXT_LIMIT).'…';
            }
            if (\is_array($value)) {
                return array_map($walk, $value);
            }

            return $value;
        };

        /** @var array<string, mixed> $safe */
        $safe = $walk($envelope);

        return [$safe, $truncated];
    }
}
