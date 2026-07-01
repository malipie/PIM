<?php

declare(strict_types=1);

namespace App\Export\Feed\Domain\Descriptor;

/**
 * Declarative XML feed structure (ADR-0023 §6.3, XMLF-P2-01) — the canonical,
 * validated shape of `feed_profiles.descriptor`. The UI edits it (XMLF-P2-07),
 * the writer executes it (XMLF-P2-05), the generator validates products against
 * its slot rules (XMLF-P2-04). This VO is the single source of truth for the
 * descriptor shape; the persist-time guard (XMLF-P1-04) delegates to it.
 *
 * Supports the RSS-like shape (root `rss` → `channel` → `item`, Google/Meta)
 * and the flat shape (root `offers` → `o`, Ceneo) — `channel` is optional.
 */
final class FeedDescriptor
{
    private const string XML_NAME = '/^([A-Za-z_][A-Za-z0-9_-]*:)?[A-Za-z_][A-Za-z0-9_-]*$/';

    /**
     * @param array<string, string>      $rootAttributes
     * @param array<string, string>      $namespaces     prefix => URI
     * @param list<array<string, mixed>> $header         channel header nodes (element + source)
     * @param list<FeedSlot>             $slots
     */
    public function __construct(
        public readonly string $rootElement,
        public readonly array $rootAttributes,
        public readonly array $namespaces,
        public readonly ?string $channelElement,
        public readonly array $header,
        public readonly string $itemElement,
        public readonly array $slots,
    ) {
    }

    /**
     * @param array<mixed, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $root = \is_array($data['root'] ?? null) ? $data['root'] : [];
        $rootElement = \is_string($root['element'] ?? null) ? $root['element'] : '';
        self::assertXmlName($rootElement, 'root element');

        $rootAttributes = self::stringMap($root['attributes'] ?? null);
        $namespaces = self::stringMap($root['namespaces'] ?? null);
        foreach (array_keys($namespaces) as $prefix) {
            self::assertXmlName($prefix, 'namespace prefix');
        }

        $channel = \is_array($data['channel'] ?? null) ? $data['channel'] : null;
        $channelElement = null;
        $header = [];
        $itemSource = $data['item'] ?? null;
        if (null !== $channel) {
            $channelElement = \is_string($channel['element'] ?? null) ? $channel['element'] : 'channel';
            self::assertXmlName($channelElement, 'channel element');
            $header = self::headerNodes($channel['header'] ?? null);
            $itemSource = $channel['item'] ?? $itemSource;
        }

        if (!\is_array($itemSource)) {
            throw new InvalidDescriptorException('Descriptor is missing an "item" definition.');
        }
        $itemElement = \is_string($itemSource['element'] ?? null) ? $itemSource['element'] : '';
        self::assertXmlName($itemElement, 'item element');

        $slots = self::slots($itemSource['slots'] ?? null);

        return new self($rootElement, $rootAttributes, $namespaces, $channelElement, $header, $itemElement, $slots);
    }

    public function findSlot(string $target): ?FeedSlot
    {
        foreach ($this->slots as $slot) {
            if ($slot->target === $target) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $root = ['element' => $this->rootElement];
        if ([] !== $this->rootAttributes) {
            $root['attributes'] = $this->rootAttributes;
        }
        if ([] !== $this->namespaces) {
            $root['namespaces'] = $this->namespaces;
        }

        $item = [
            'element' => $this->itemElement,
            'slots' => array_map(static fn (FeedSlot $s): array => $s->toArray(), $this->slots),
        ];

        if (null === $this->channelElement) {
            return ['root' => $root, 'item' => $item];
        }

        return [
            'root' => $root,
            'channel' => ['element' => $this->channelElement, 'header' => $this->header, 'item' => $item],
        ];
    }

    /**
     * @return list<FeedSlot>
     */
    private static function slots(mixed $raw): array
    {
        if (!\is_array($raw)) {
            throw new InvalidDescriptorException('Descriptor item is missing a "slots" list.');
        }

        $slots = [];
        $targets = [];
        foreach ($raw as $slotData) {
            if (!\is_array($slotData)) {
                throw new InvalidDescriptorException('Each slot must be an object.');
            }
            $slot = FeedSlot::fromArray($slotData);
            if (isset($targets[$slot->target])) {
                throw new InvalidDescriptorException(sprintf('Duplicate slot target "%s".', $slot->target));
            }
            $targets[$slot->target] = true;
            $slots[] = $slot;
        }

        // requiredOneOf references must point at existing slot targets.
        foreach ($slots as $slot) {
            foreach ($slot->rule->requiredOneOf as $ref) {
                if (!isset($targets[$ref])) {
                    throw new InvalidDescriptorException(sprintf('Slot "%s": requiredOneOf references unknown slot "%s".', $slot->target, $ref));
                }
            }
        }

        return $slots;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function headerNodes(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $nodes = [];
        foreach ($raw as $node) {
            if (!\is_array($node)) {
                continue;
            }
            $element = \is_string($node['element'] ?? null) ? $node['element'] : '';
            self::assertXmlName($element, 'header element');
            $clean = [];
            foreach ($node as $key => $value) {
                if (\is_string($key)) {
                    $clean[$key] = $value;
                }
            }
            $nodes[] = $clean;
        }

        return $nodes;
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $map = [];
        foreach ($raw as $key => $value) {
            if (\is_string($key) && \is_scalar($value)) {
                $map[$key] = (string) $value;
            }
        }

        return $map;
    }

    private static function assertXmlName(string $name, string $context): void
    {
        if (1 !== preg_match(self::XML_NAME, $name)) {
            throw new InvalidDescriptorException(sprintf('%s "%s" is not a valid XML name.', ucfirst($context), $name));
        }
    }
}
