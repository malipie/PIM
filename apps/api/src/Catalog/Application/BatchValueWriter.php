<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\Provenance;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * IMP2-1.4 (#1466, ADR-0019) — chunk-oriented value writer for bulk paths
 * (import). Shares every rule with the admin path via {@see ValueWriteCore},
 * but: collects violations as result issues instead of throwing, persists
 * without flushing (the batch handler owns flushAndClear), and prefetches
 * existing ObjectValue rows per chunk so the update path costs one query
 * per chunk instead of one per value (the deliberate N+1 of IMP2-1.3).
 *
 * Lifecycle per chunk: primeChunk(objects, attributes) once, then
 * writeMany() per row. EntityManager::clear() invalidates the prime —
 * the import handler re-primes after every flushAndClear.
 */
final class BatchValueWriter
{
    /** @var array<string, ObjectValue> "objectId|attributeId|locale|channelId" => row */
    private array $scopeIndex = [];

    /** @var array<string, true> "attributeId|value" of identifier values seen in this chunk (in-file duplicates) */
    private array $chunkIdentifiers = [];

    /**
     * DP-07 (#2037) — objectId => code => existing NON-EMPTY global envelope,
     * built in the same primeChunk() row loop (zero extra queries). Feeds the
     * cross-field pre-pass in writeMany().
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $globalEnvelopeIndex = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValueWriteCore $core,
        private readonly CrossFieldRulesValidator $crossFieldRules,
    ) {
    }

    /**
     * One query: every ObjectValue of the chunk's existing objects for the
     * mapped attributes. New (just-persisted, unflushed) objects have no
     * rows yet and simply miss the index.
     *
     * @param list<CatalogObject>      $objects
     * @param array<string, Attribute> $attributesByCode
     */
    public function primeChunk(array $objects, array $attributesByCode): void
    {
        $this->scopeIndex = [];
        $this->chunkIdentifiers = [];
        $this->globalEnvelopeIndex = [];

        if ([] === $objects || [] === $attributesByCode) {
            return;
        }

        $rows = $this->em->createQueryBuilder()
            ->select('ov')
            ->from(ObjectValue::class, 'ov')
            ->where('ov.object IN (:objects)')
            ->andWhere('ov.attribute IN (:attributes)')
            ->setParameter('objects', $objects)
            ->setParameter('attributes', array_values($attributesByCode))
            ->getQuery()
            ->getResult();

        /** @var list<ObjectValue> $rows */
        foreach ($rows as $row) {
            $this->scopeIndex[$this->scopeKey(
                $row->getObject()->getId(),
                $row->getAttribute()->getId(),
                $row->getLocale(),
                $row->getChannelId(),
            )] = $row;

            if (null === $row->getLocale() && null === $row->getChannelId()
                && !ValueWriteCore::isEmptyEnvelope($row->getValue())) {
                $this->globalEnvelopeIndex[$row->getObject()->getId()->toRfc4122()][$row->getAttribute()->getCode()] = $row->getValue();
            }
        }
    }

    /**
     * Apply pre-built envelopes to one object. Issues are returned, never
     * thrown; a value with an issue is skipped, the rest of the row writes.
     *
     * @param list<array{attribute: Attribute, envelope: array<string, mixed>, locale: ?string, channelId: ?Uuid}> $writes
     *
     * @return array{issues: list<array{attributeCode: string, kind: string, message: string}>, changed: int} `changed`
     *                                                                                                        counts the values actually created or updated; IMP2-2.6 skips
     *                                                                                                        a write whose value AND provenance already match (re-import of
     *                                                                                                        an unchanged export), so a row with `changed === 0` is a no-op
     *                                                                                                        the caller counts as `skipped` rather than `updated`
     */
    public function writeMany(CatalogObject $object, array $writes, Provenance $provenance): array
    {
        $tenant = $object->getTenant();
        $issues = [];
        $changed = 0;

        // Normalise + route once; the per-write loop below and the DP-07
        // cross-field pre-pass share the prepared entries.
        $prepared = [];
        foreach ($writes as $write) {
            $attribute = $write['attribute'];
            [$targetLocale, $targetChannel] = $tenant instanceof Tenant
                ? $this->core->routeScope($attribute, $tenant, $write['locale'], $write['channelId'])
                : [$write['locale'], $write['channelId']];
            $prepared[] = [
                'attribute' => $attribute,
                'envelope' => $this->core->normalise($attribute->getType(), $write['envelope']),
                'locale' => $targetLocale,
                'channelId' => $targetChannel,
            ];
        }

        // DP-07 (#2037, ADR-0025) — cross-field pre-pass: evaluate the rules
        // against existing global envelopes (primeChunk index) overlaid with
        // this row's writes. Broken `compare` skips the implicated incoming
        // writes (invalid data must not land); `require_when` is report-only
        // (the target is empty — there is nothing to skip).
        $skipCodes = [];
        $rules = $this->crossFieldRules->rulesFor($object->getObjectType());
        if ([] !== $rules) {
            $baseView = $this->globalEnvelopeIndex[$object->getId()->toRfc4122()] ?? [];
            $view = $this->crossFieldRules->overlay($baseView, $prepared);
            foreach ($this->crossFieldRules->evaluate($rules, $view) as $violation) {
                $issues[] = ['attributeCode' => $violation->attributeCode, 'kind' => 'cross_field', 'message' => $violation->message];
                if ('compare' === $violation->ruleType) {
                    foreach ($violation->referencedCodes as $code) {
                        $skipCodes[$code] = true;
                    }
                }
            }
        }

        foreach ($prepared as $write) {
            $attribute = $write['attribute'];
            $envelope = $write['envelope'];

            if (isset($skipCodes[$attribute->getCode()])) {
                continue;
            }

            $requiredViolation = $this->core->requiredViolation($attribute, $envelope);
            if (null !== $requiredViolation) {
                $issues[] = ['attributeCode' => $attribute->getCode(), 'kind' => 'required_empty', 'message' => $requiredViolation];

                continue;
            }

            $formatViolations = $this->core->formatViolations($attribute, $envelope);
            if ([] !== $formatViolations) {
                foreach ($formatViolations as $message) {
                    $issues[] = ['attributeCode' => $attribute->getCode(), 'kind' => 'invalid_value', 'message' => $message];
                }

                continue;
            }

            // Identifier uniqueness: in-chunk set first (file duplicates the
            // DB cannot see yet), then the per-value DB pre-check.
            $identifierCandidate = $envelope['value'] ?? null;
            if (AttributeType::Identifier === $attribute->getType()
                && \is_string($identifierCandidate) && '' !== $identifierCandidate) {
                $chunkKey = $attribute->getId()->toRfc4122().'|'.$identifierCandidate;
                if (isset($this->chunkIdentifiers[$chunkKey])) {
                    $issues[] = ['attributeCode' => $attribute->getCode(), 'kind' => 'duplicate_identifier', 'message' => \sprintf('Identifier "%s" duplicated within the imported chunk.', $identifierCandidate)];

                    continue;
                }
                $duplicate = $this->core->duplicateIdentifier($object, $attribute, $envelope);
                if (null !== $duplicate) {
                    $issues[] = ['attributeCode' => $attribute->getCode(), 'kind' => 'duplicate_identifier', 'message' => \sprintf('Identifier "%s" is already assigned to another %s.', $duplicate, $object->getObjectType()->getCode())];

                    continue;
                }
                $this->chunkIdentifiers[$chunkKey] = true;
            }

            // Scope already routed in the prepare pass.
            $targetLocale = $write['locale'];
            $targetChannel = $write['channelId'];

            $existing = $this->scopeIndex[$this->scopeKey($object->getId(), $attribute->getId(), $targetLocale, $targetChannel)] ?? null;
            if ($existing instanceof ObjectValue) {
                // IMP2-2.6 — compare-values diff: an identical value AND identical
                // provenance is a no-op, so skip the UPDATE entirely (re-importing
                // an unchanged export must not churn object_values). An identical
                // value under a DIFFERENT provenance is NOT a no-op — the import
                // takes ownership, so we still write to reflect that in the audit.
                if ($provenance === $existing->getProvenance() && $existing->getValue() === $envelope) {
                    continue;
                }
                $existing->updateValue($envelope);
                $existing->changeProvenance($provenance);
                ++$changed;

                continue;
            }

            $value = new ObjectValue(
                object: $object,
                attribute: $attribute,
                value: $envelope,
                provenance: $provenance,
                channelId: $targetChannel,
                locale: $targetLocale,
            );
            $this->em->persist($value);
            $this->scopeIndex[$this->scopeKey($object->getId(), $attribute->getId(), $targetLocale, $targetChannel)] = $value;
            ++$changed;
        }

        return ['issues' => $issues, 'changed' => $changed];
    }

    private function scopeKey(Uuid $objectId, Uuid $attributeId, ?string $locale, ?Uuid $channelId): string
    {
        return $objectId->toRfc4122().'|'.$attributeId->toRfc4122().'|'.($locale ?? '').'|'.($channelId?->toRfc4122() ?? '');
    }
}
