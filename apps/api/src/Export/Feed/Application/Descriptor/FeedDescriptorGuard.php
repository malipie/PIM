<?php

declare(strict_types=1);

namespace App\Export\Feed\Application\Descriptor;

use App\Export\Feed\Domain\Descriptor\FeedDescriptor;
use App\Export\Feed\Domain\Descriptor\InvalidDescriptorException;

/**
 * Persist-time guard for a feed descriptor (ADR-0023, XMLF-P1-04). Runs before
 * a FeedProfile.descriptor JSONB is saved (XMLF-P1-03): checks the top-level
 * shape and delegates the full structural validation to the canonical
 * {@see FeedDescriptor} value object (XMLF-P2-01) — the single source of truth.
 *
 * A lightweight guard whose only job is to fail a bad save early with a clear
 * message instead of persisting a descriptor the generator/writer cannot run.
 */
final class FeedDescriptorGuard
{
    /**
     * @param array<mixed, mixed> $descriptor
     *
     * @throws InvalidDescriptorException when the descriptor is not persistable
     */
    public function assertValid(array $descriptor): FeedDescriptor
    {
        if ([] === $descriptor) {
            throw new InvalidDescriptorException('Feed descriptor must not be empty.');
        }
        if (!\array_key_exists('root', $descriptor)) {
            throw new InvalidDescriptorException('Feed descriptor is missing the "root" element.');
        }
        if (!\array_key_exists('channel', $descriptor) && !\array_key_exists('item', $descriptor)) {
            throw new InvalidDescriptorException('Feed descriptor must define an "item" (directly or under "channel").');
        }

        // Full structural validation (node kinds, XML names, requiredOneOf refs,
        // duplicate targets, …) is the value object's responsibility.
        return FeedDescriptor::fromArray($descriptor);
    }

    /**
     * @param array<mixed, mixed> $descriptor
     */
    public function isValid(array $descriptor): bool
    {
        try {
            $this->assertValid($descriptor);

            return true;
        } catch (InvalidDescriptorException) {
            return false;
        }
    }
}
