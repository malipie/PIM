<?php

declare(strict_types=1);

namespace App\Export\Feed\Application\Mapping;

use App\Catalog\Contracts\Query\AttributeSummary;
use App\Export\Feed\Domain\Descriptor\FeedDescriptor;
use App\Export\Feed\Domain\Descriptor\FeedSlot;
use App\Export\Feed\Domain\Entity\FeedProfile;
use App\Export\Feed\Domain\Mapping\FeedFieldMapping;

/**
 * Mapper read model + write validator (ADR-0023 §6.4, XMLF-P3-01).
 *
 * The mapper is where the operator wires each descriptor slot to a PIM
 * attribute (or a static/template source) and an optional transform. This
 * service projects the mapper state the UI (XMLF-P5-03) renders — slots with
 * their current mapping, the tenant attribute catalog, coverage counters and
 * advisory type warnings — and validates a PUT payload before it is persisted
 * onto {@see FeedProfile::updateFieldMappings()}.
 *
 * The attribute catalog is read through the cross-BC
 * {@see \App\Catalog\Contracts\Service\AttributeCatalogReader} seam (the
 * caller passes the already-fetched list), so the Feed sub-area never reaches
 * into Catalog internals.
 */
final class FeedMappingService
{
    /**
     * Transform kinds recognised by {@see \App\Export\Feed\Domain\Mapping\FeedTransformApplier}.
     */
    public const array TRANSFORMS = [
        'default',
        'price',
        'number',
        'date',
        'enum_map',
        'template',
        'strip_html',
        'truncate',
    ];

    private const array SOURCE_KINDS = ['attribute', 'static', 'template'];

    public function __construct(
        private readonly SlotFormatCompatibility $compatibility = new SlotFormatCompatibility(),
    ) {
    }

    /**
     * @param list<AttributeSummary> $attributes
     *
     * @return array<string, mixed>
     */
    public function view(FeedProfile $feed, array $attributes): array
    {
        $descriptor = FeedDescriptor::fromArray($feed->getDescriptor());
        $mappings = $this->indexMappings(FeedFieldMapping::listFromArray($feed->getFieldMappings()));
        $typeByCode = $this->attributeTypes($attributes);

        $slots = [];
        $requiredTotal = 0;
        $requiredMapped = 0;
        $missingRequired = [];
        $mappedCount = 0;

        foreach ($descriptor->slots as $slot) {
            $mapping = $mappings[$slot->target] ?? null;
            $mapped = null !== $mapping && $this->hasSource($mapping);
            if ($mapped) {
                ++$mappedCount;
            }
            if ($slot->rule->required) {
                ++$requiredTotal;
                if ($mapped) {
                    ++$requiredMapped;
                } else {
                    $missingRequired[] = $slot->target;
                }
            }

            $slots[] = [
                'target' => $slot->target,
                'element' => $slot->element,
                'node' => $slot->node->value,
                'required' => $slot->rule->required,
                'required_one_of' => $slot->rule->requiredOneOf,
                'format' => $slot->rule->format->value,
                'max_length' => $slot->rule->maxLength,
                'enums' => $slot->rule->enums,
                'mapping' => null === $mapping ? null : $this->serializeMapping($mapping),
                'mapped' => $mapped,
                'type_warning' => $this->typeWarning($slot, $mapping, $typeByCode),
            ];
        }

        return [
            'feed_id' => $feed->getId()->toRfc4122(),
            'object_type_id' => $feed->getObjectTypeId()->toRfc4122(),
            'slots' => $slots,
            'attributes' => array_map($this->serializeAttribute(...), $attributes),
            'coverage' => [
                'slots_total' => \count($descriptor->slots),
                'slots_mapped' => $mappedCount,
                'required_total' => $requiredTotal,
                'required_mapped' => $requiredMapped,
                'missing_required' => $missingRequired,
                'one_of_groups' => $this->oneOfGroups($descriptor->slots, $mappings),
            ],
            'transforms' => self::TRANSFORMS,
        ];
    }

    /**
     * Validate a PUT payload and write the normalised mappings onto the feed.
     * The caller persists the feed afterwards.
     *
     * @param array<string, mixed>   $payload    {mappings: list<{slot, source, transform?}>}
     * @param list<AttributeSummary> $attributes tenant attribute catalog
     *
     * @throws InvalidMappingException
     */
    public function applyUpdate(FeedProfile $feed, array $payload, array $attributes): void
    {
        $raw = $payload['mappings'] ?? null;
        if (!\is_array($raw)) {
            throw new InvalidMappingException('Pole "mappings" jest wymagane (lista mapowań).');
        }

        $descriptor = FeedDescriptor::fromArray($feed->getDescriptor());
        $slotTargets = [];
        foreach ($descriptor->slots as $slot) {
            $slotTargets[$slot->target] = true;
        }
        $attributeCodes = [];
        foreach ($attributes as $attribute) {
            $attributeCodes[$attribute->code] = true;
        }

        $normalised = [];
        $seenSlots = [];
        foreach ($raw as $entry) {
            if (!\is_array($entry)) {
                throw new InvalidMappingException('Każde mapowanie musi być obiektem.');
            }
            $normalised[] = $this->normaliseEntry($entry, $slotTargets, $attributeCodes, $seenSlots);
        }

        $feed->updateFieldMappings($normalised);
    }

    /**
     * @param array<mixed, mixed> $entry
     * @param array<string, true> $slotTargets
     * @param array<string, true> $attributeCodes
     * @param array<string, true> $seenSlots
     *
     * @return array<string, mixed>
     */
    private function normaliseEntry(array $entry, array $slotTargets, array $attributeCodes, array &$seenSlots): array
    {
        $slot = $entry['slot'] ?? null;
        if (!\is_string($slot) || !isset($slotTargets[$slot])) {
            throw new InvalidMappingException(sprintf('Nieznany slot "%s" (brak w deskryptorze feedu).', \is_string($slot) ? $slot : ''));
        }
        if (isset($seenSlots[$slot])) {
            throw new InvalidMappingException(sprintf('Slot "%s" zmapowany więcej niż raz.', $slot));
        }
        $seenSlots[$slot] = true;

        $source = \is_array($entry['source'] ?? null) ? $entry['source'] : [];
        $kind = \is_string($source['kind'] ?? null) ? $source['kind'] : null;
        if (null === $kind || !\in_array($kind, self::SOURCE_KINDS, true)) {
            throw new InvalidMappingException(sprintf('Slot "%s": nieznany typ źródła (attribute|static|template).', $slot));
        }

        $normalisedSource = ['kind' => $kind];
        if ('attribute' === $kind) {
            $ref = \is_string($source['ref'] ?? null) ? $source['ref'] : '';
            if ('' === $ref || !isset($attributeCodes[$ref])) {
                throw new InvalidMappingException(sprintf('Slot "%s": atrybut "%s" nie istnieje w katalogu.', $slot, $ref));
            }
            $normalisedSource['ref'] = $ref;
        } else {
            $value = \is_string($source['value'] ?? null) ? $source['value'] : '';
            if ('' === $value) {
                throw new InvalidMappingException(sprintf('Slot "%s": źródło "%s" wymaga wartości.', $slot, $kind));
            }
            $normalisedSource['value'] = $value;
        }

        $out = ['slot' => $slot, 'source' => $normalisedSource];

        $transform = $entry['transform'] ?? null;
        if (\is_array($transform)) {
            $transformKind = \is_string($transform['kind'] ?? null) ? $transform['kind'] : null;
            if (null === $transformKind || !\in_array($transformKind, self::TRANSFORMS, true)) {
                throw new InvalidMappingException(sprintf('Slot "%s": nieznana transformacja "%s".', $slot, $transformKind ?? ''));
            }
            $out['transform'] = $transform;
        }

        return $out;
    }

    /**
     * @param list<FeedFieldMapping> $mappings
     *
     * @return array<string, FeedFieldMapping>
     */
    private function indexMappings(array $mappings): array
    {
        $byTarget = [];
        foreach ($mappings as $mapping) {
            $byTarget[$mapping->slot] = $mapping;
        }

        return $byTarget;
    }

    private function hasSource(FeedFieldMapping $mapping): bool
    {
        return ('attribute' === $mapping->sourceKind && null !== $mapping->sourceRef && '' !== $mapping->sourceRef)
            || (\in_array($mapping->sourceKind, ['static', 'template'], true) && null !== $mapping->sourceValue && '' !== $mapping->sourceValue);
    }

    /**
     * @return array<string, string>
     */
    private function serializeMapping(FeedFieldMapping $mapping): array
    {
        return array_filter([
            'source_kind' => $mapping->sourceKind ?? '',
            'source_ref' => $mapping->sourceRef ?? '',
            'source_value' => $mapping->sourceValue ?? '',
            'transform' => \is_array($mapping->transform) && \is_string($mapping->transform['kind'] ?? null)
                ? $mapping->transform['kind']
                : '',
        ], static fn (string $v): bool => '' !== $v);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAttribute(AttributeSummary $attribute): array
    {
        return [
            'id' => $attribute->id->toRfc4122(),
            'code' => $attribute->code,
            'label' => $attribute->label,
            'type' => $attribute->type,
            'localizable' => $attribute->isLocalizable,
            'group_code' => $attribute->groupCode,
            'group_label' => $attribute->groupLabel,
        ];
    }

    /**
     * @param array<string, string> $typeByCode
     */
    private function typeWarning(FeedSlot $slot, ?FeedFieldMapping $mapping, array $typeByCode): ?string
    {
        if (null === $mapping || 'attribute' !== $mapping->sourceKind || null === $mapping->sourceRef) {
            return null;
        }
        $type = $typeByCode[$mapping->sourceRef] ?? null;
        if (null === $type) {
            return sprintf('Atrybut "%s" nie istnieje w katalogu.', $mapping->sourceRef);
        }

        return $this->compatibility->warn($slot->rule->format, $type);
    }

    /**
     * @param list<AttributeSummary> $attributes
     *
     * @return array<string, string>
     */
    private function attributeTypes(array $attributes): array
    {
        $types = [];
        foreach ($attributes as $attribute) {
            $types[$attribute->code] = $attribute->type;
        }

        return $types;
    }

    /**
     * @param list<FeedSlot>                  $slots
     * @param array<string, FeedFieldMapping> $mappings
     *
     * @return list<array<string, mixed>>
     */
    private function oneOfGroups(array $slots, array $mappings): array
    {
        $groups = [];
        foreach ($slots as $slot) {
            $targets = $slot->rule->requiredOneOf;
            if ([] === $targets) {
                continue;
            }
            sort($targets);
            $groups[implode('|', $targets)] = $targets;
        }

        $out = [];
        foreach ($groups as $targets) {
            $satisfied = false;
            foreach ($targets as $target) {
                if (isset($mappings[$target]) && $this->hasSource($mappings[$target])) {
                    $satisfied = true;
                    break;
                }
            }
            $out[] = ['targets' => $targets, 'satisfied' => $satisfied];
        }

        return $out;
    }
}
