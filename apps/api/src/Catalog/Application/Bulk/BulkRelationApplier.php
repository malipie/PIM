<?php

declare(strict_types=1);

namespace App\Catalog\Application\Bulk;

use App\Catalog\Application\Lock\AttributeLockReader;
use App\Catalog\Application\ObjectRelationService;
use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\BulkLog;
use App\Catalog\Domain\Entity\BulkSession;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectRelation;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Shared\Application\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * #2314 — relation-attribute lane for the bulk value actions.
 *
 * Relation values live in the `object_relations` link table, not in
 * `object_values`/`attributes_indexed`, so the scalar bulk handlers cannot
 * write them (they would only pollute the denormalised JSONB with raw ids).
 * Every bulk value handler (set / clear / append / remove) detects a
 * relation attribute via {@see self::relationAttributeId()} and delegates
 * the per-object mutation here, which routes through
 * {@see ObjectRelationService::replaceForSourceAndAttribute()} — the same
 * write path the detail page uses (target/tenant/cardinality guards
 * included).
 *
 * Per-link metadata of targets that survive an append/remove is preserved
 * by re-submitting the existing metadata map; targets added by the bulk
 * action start with empty metadata.
 *
 * The attribute is re-resolved by id per object because the shared
 * {@see AbstractBulkHandler} clears the EntityManager every chunk, which
 * would detach an Attribute captured in handle().
 */
final readonly class BulkRelationApplier
{
    public const string MODE_REPLACE = 'replace';
    public const string MODE_CLEAR = 'clear';
    public const string MODE_APPEND = 'append';
    public const string MODE_REMOVE = 'remove';

    public function __construct(
        private AttributeRepositoryInterface $attributes,
        private ObjectRelationService $relations,
        private AttributeLockReader $lockReader,
        private TenantContext $tenantContext,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * Resolve `$attrCode` to the Attribute id when (and only when) it is a
     * relation-type attribute of the current tenant. Null → the caller
     * stays on its scalar attributesIndexed path.
     */
    public function relationAttributeId(string $attrCode): ?Uuid
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant || '' === $attrCode) {
            return null;
        }

        $attribute = $this->attributes->findByCode($attrCode, $tenant);
        if (null === $attribute || AttributeType::Relation !== $attribute->getType()) {
            return null;
        }

        return $attribute->getId();
    }

    /**
     * Normalise the wizard payload into a list of target object UUIDs.
     * Accepts a single UUID string (cardinality=one Combobox) or a list of
     * UUID strings (MultiSelect). Anything else is a client bug → 400.
     *
     * @return list<string>
     */
    public function parseTargetIds(mixed $value): array
    {
        if (null === $value || '' === $value) {
            return [];
        }
        $raw = \is_array($value) ? $value : [$value];

        $ids = [];
        foreach ($raw as $entry) {
            if (!\is_string($entry) || !Uuid::isValid($entry)) {
                throw new BadRequestHttpException(
                    'Relation attribute value must be a target object UUID or an array of UUIDs.',
                );
            }
            $ids[] = $entry;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Apply one bulk relation mutation to a single object, mirroring the
     * scalar handlers' skip/log/counter semantics.
     *
     * @param self::MODE_* $mode
     * @param list<string> $targetIds
     */
    public function apply(
        string $mode,
        CatalogObject $object,
        Uuid $attributeId,
        array $targetIds,
        BulkSession $session,
        BulkCounters $counters,
    ): void {
        $attribute = $this->attributes->findById($attributeId);
        if (null === $attribute) {
            ++$counters->error;
            $this->em->persist(new BulkLog(
                $session->getId(),
                $object->getId(),
                null,
                null,
                null,
                BulkLog::LEVEL_ERROR,
                'Relation attribute vanished mid-run',
            ));

            return;
        }

        $existing = $this->relations->listForSource($object, $attribute);
        $old = array_map(
            static fn (ObjectRelation $row): string => $row->getTarget()->getId()->toRfc4122(),
            $existing,
        );
        $metadataByTarget = [];
        foreach ($existing as $row) {
            $metadataByTarget[$row->getTarget()->getId()->toRfc4122()] = $row->getMetadata();
        }

        if ($this->lockReader->isLocked($object, $attribute->getCode())) {
            ++$counters->skipped;
            $this->em->persist(new BulkLog(
                $session->getId(),
                $object->getId(),
                null,
                $old,
                $old,
                BulkLog::LEVEL_WARNING,
                'Attribute locked',
            ));

            return;
        }

        $next = match ($mode) {
            self::MODE_REPLACE => $targetIds,
            self::MODE_CLEAR => [],
            self::MODE_APPEND => array_values(array_unique([...$old, ...$targetIds])),
            self::MODE_REMOVE => array_values(array_diff($old, $targetIds)),
        };

        if (self::MODE_APPEND === $mode && $next === $old) {
            ++$counters->skipped;
            $this->em->persist(new BulkLog(
                $session->getId(),
                $object->getId(),
                null,
                $old,
                $old,
                BulkLog::LEVEL_WARNING,
                'Value already present',
            ));

            return;
        }
        if (self::MODE_REMOVE === $mode && $next === $old) {
            ++$counters->skipped;
            $this->em->persist(new BulkLog(
                $session->getId(),
                $object->getId(),
                null,
                $old,
                $old,
                BulkLog::LEVEL_WARNING,
                'Value not present',
            ));

            return;
        }

        $this->relations->replaceForSourceAndAttribute(
            $object,
            $attribute,
            array_map(
                static fn (string $id): array => [
                    'id' => Uuid::fromString($id),
                    'metadata' => $metadataByTarget[$id] ?? [],
                ],
                $next,
            ),
        );
        $object->markTouchedByBulkSession($session->getId());

        $this->em->persist(new BulkLog(
            $session->getId(),
            $object->getId(),
            null,
            $old,
            $next,
            BulkLog::LEVEL_INFO,
            null,
        ));
        ++$counters->success;
    }

    /**
     * Rollback lane: restore the target set recorded as the BulkLog "old"
     * value. Metadata of restored links is not recovered (the log stores
     * ids only) — parity with the scalar rollback, which restores values
     * without provenance history.
     */
    public function revertTo(CatalogObject $object, Uuid $attributeId, mixed $oldValue): bool
    {
        $attribute = $this->attributes->findById($attributeId);
        if (null === $attribute) {
            return false;
        }

        $ids = [];
        if (\is_array($oldValue)) {
            foreach ($oldValue as $entry) {
                if (\is_string($entry) && Uuid::isValid($entry)) {
                    $ids[] = ['id' => Uuid::fromString($entry), 'metadata' => []];
                }
            }
        }

        $this->relations->replaceForSourceAndAttribute($object, $attribute, $ids);

        return true;
    }
}
