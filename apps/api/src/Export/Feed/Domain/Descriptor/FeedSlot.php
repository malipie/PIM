<?php

declare(strict_types=1);

namespace App\Export\Feed\Domain\Descriptor;

/**
 * One output field of a feed's `<item>` (ADR-0023 §6.3, XMLF-P2-01).
 *
 * `target` is the logical key mappings reference (`g:id`, `ceneo_image`);
 * `element` is the XML element/attribute local name (a valid NCName / QName —
 * this is what makes `description.pl` illegal as a raw element and forces the
 * feed to use clean slot names). `parent` names the element an Attribute node
 * hangs off (Ceneo `<o price="…">`); `wrapIn` names an optional grouping
 * element (Ceneo `<imgs>` around repeated `<i>`).
 */
final class FeedSlot
{
    /** XML name: optional single namespace prefix + NCName local part; no dots. */
    private const string XML_NAME = '/^([A-Za-z_][A-Za-z0-9_-]*:)?[A-Za-z_][A-Za-z0-9_-]*$/';

    public function __construct(
        public readonly string $target,
        public readonly SlotNodeKind $node,
        public readonly string $element,
        public readonly ?string $parent,
        public readonly ?string $wrapIn,
        public readonly SlotValidationRule $rule,
    ) {
    }

    /**
     * @param array<mixed, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $target = $data['target'] ?? $data['slot'] ?? null;
        if (!\is_string($target) || '' === $target) {
            throw new InvalidDescriptorException('Feed slot is missing a non-empty "target".');
        }

        $nodeRaw = \is_string($data['node'] ?? null) ? $data['node'] : 'element';
        $node = SlotNodeKind::tryFrom($nodeRaw);
        if (null === $node) {
            throw new InvalidDescriptorException(sprintf('Slot "%s": unknown node kind "%s".', $target, $nodeRaw));
        }

        $element = \is_string($data['element'] ?? null) ? $data['element'] : $target;
        if (1 !== preg_match(self::XML_NAME, $element)) {
            throw new InvalidDescriptorException(sprintf('Slot "%s": "%s" is not a valid XML element name.', $target, $element));
        }

        $parent = \is_string($data['parent'] ?? null) ? $data['parent'] : null;
        if (SlotNodeKind::Attribute === $node && (null === $parent || '' === $parent)) {
            throw new InvalidDescriptorException(sprintf('Slot "%s": an attribute node requires a "parent" element.', $target));
        }
        if (null !== $parent && 1 !== preg_match(self::XML_NAME, $parent)) {
            throw new InvalidDescriptorException(sprintf('Slot "%s": parent "%s" is not a valid XML element name.', $target, $parent));
        }

        $wrapIn = \is_string($data['wrapIn'] ?? null) ? $data['wrapIn'] : null;
        if (null !== $wrapIn && 1 !== preg_match(self::XML_NAME, $wrapIn)) {
            throw new InvalidDescriptorException(sprintf('Slot "%s": wrapIn "%s" is not a valid XML element name.', $target, $wrapIn));
        }

        $rule = SlotValidationRule::fromArray($data);
        if (SlotFormat::Enum === $rule->format && [] === $rule->enums) {
            throw new InvalidDescriptorException(sprintf('Slot "%s": format "enum" requires a non-empty "enums" list.', $target));
        }

        return new self($target, $node, $element, $parent, $wrapIn, $rule);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['target' => $this->target, 'node' => $this->node->value, 'element' => $this->element];
        if (null !== $this->parent) {
            $out['parent'] = $this->parent;
        }
        if (null !== $this->wrapIn) {
            $out['wrapIn'] = $this->wrapIn;
        }

        return [...$out, ...$this->rule->toArray()];
    }
}
