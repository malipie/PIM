<?php

declare(strict_types=1);

namespace App\Export\Feed\Application\Descriptor;

use App\Export\Feed\Domain\Descriptor\FeedDescriptor;
use App\Export\Feed\Domain\Descriptor\FeedSlot;
use App\Export\Feed\Domain\Descriptor\InvalidDescriptorException;

/**
 * Structure-editing operations for a custom feed descriptor (ADR-0023 §7,
 * XMLF-P2-07): define the root / namespaces and add / rename / remove slots,
 * so a client can build a custom feed "from scratch" (not just clone a predef).
 *
 * Each operation parses the input through {@see FeedDescriptor} (rejecting a
 * bad starting point), rebuilds the descriptor with the edited slot list and
 * re-validates the result — so the editor can never persist a broken structure.
 * Only `custom` feeds are structure-editable; the CRUD (XMLF-P1-03) enforces
 * that predefs stay immutable.
 */
final class FeedDescriptorEditor
{
    /**
     * @param array<mixed, mixed>   $descriptor
     * @param array<string, string> $attributes
     * @param array<string, string> $namespaces prefix => URI
     *
     * @return array<string, mixed>
     */
    public function setRoot(array $descriptor, string $rootElement, array $attributes = [], array $namespaces = []): array
    {
        $current = FeedDescriptor::fromArray($descriptor);

        return $this->build(
            $rootElement,
            $attributes,
            $namespaces,
            $current->channelElement,
            $current->header,
            $current->itemElement,
            $this->slotList($current),
        );
    }

    /**
     * @param array<mixed, mixed> $descriptor
     * @param array<mixed, mixed> $slot
     *
     * @return array<string, mixed>
     */
    public function addSlot(array $descriptor, array $slot): array
    {
        $current = FeedDescriptor::fromArray($descriptor);
        $slots = $this->slotList($current);
        $slots[] = FeedSlot::fromArray($slot)->toArray();

        return $this->rebuild($current, $slots);
    }

    /**
     * @param array<mixed, mixed> $descriptor
     *
     * @return array<string, mixed>
     */
    public function removeSlot(array $descriptor, string $target): array
    {
        $current = FeedDescriptor::fromArray($descriptor);
        if (null === $current->findSlot($target)) {
            throw new InvalidDescriptorException(sprintf('Cannot remove unknown slot "%s".', $target));
        }

        $slots = array_values(array_filter(
            $this->slotList($current),
            static fn (array $slot): bool => ($slot['target'] ?? null) !== $target,
        ));

        return $this->rebuild($current, $slots);
    }

    /**
     * @param array<mixed, mixed> $descriptor
     *
     * @return array<string, mixed>
     */
    public function renameSlot(array $descriptor, string $from, string $to): array
    {
        $current = FeedDescriptor::fromArray($descriptor);
        if (null === $current->findSlot($from)) {
            throw new InvalidDescriptorException(sprintf('Cannot rename unknown slot "%s".', $from));
        }

        $slots = array_map(
            static function (array $slot) use ($from, $to): array {
                if (($slot['target'] ?? null) === $from) {
                    $slot['target'] = $to;
                }
                if (isset($slot['requiredOneOf']) && \is_array($slot['requiredOneOf'])) {
                    $slot['requiredOneOf'] = array_map(
                        static fn (mixed $ref): mixed => $ref === $from ? $to : $ref,
                        $slot['requiredOneOf'],
                    );
                }

                return $slot;
            },
            $this->slotList($current),
        );

        return $this->rebuild($current, $slots);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function slotList(FeedDescriptor $descriptor): array
    {
        return array_map(static fn (FeedSlot $slot): array => $slot->toArray(), $descriptor->slots);
    }

    /**
     * @param list<array<string, mixed>> $slots
     *
     * @return array<string, mixed>
     */
    private function rebuild(FeedDescriptor $current, array $slots): array
    {
        return $this->build(
            $current->rootElement,
            $current->rootAttributes,
            $current->namespaces,
            $current->channelElement,
            $current->header,
            $current->itemElement,
            $slots,
        );
    }

    /**
     * @param array<string, string>      $attributes
     * @param array<string, string>      $namespaces
     * @param list<array<string, mixed>> $header
     * @param list<array<string, mixed>> $slots
     *
     * @return array<string, mixed>
     */
    private function build(
        string $rootElement,
        array $attributes,
        array $namespaces,
        ?string $channelElement,
        array $header,
        string $itemElement,
        array $slots,
    ): array {
        $root = ['element' => $rootElement];
        if ([] !== $attributes) {
            $root['attributes'] = $attributes;
        }
        if ([] !== $namespaces) {
            $root['namespaces'] = $namespaces;
        }

        $item = ['element' => $itemElement, 'slots' => $slots];

        $descriptor = null === $channelElement
            ? ['root' => $root, 'item' => $item]
            : ['root' => $root, 'channel' => ['element' => $channelElement, 'header' => $header, 'item' => $item]];

        // Re-validate — never return a structurally broken descriptor.
        FeedDescriptor::fromArray($descriptor);

        return $descriptor;
    }
}
