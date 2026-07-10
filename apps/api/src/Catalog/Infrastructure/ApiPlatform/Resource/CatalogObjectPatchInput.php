<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ApiPlatform\Resource;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * PATCH input shape for `/api/{products,categories,assets}/{id}`.
 *
 * `code` and `objectTypeId` are intentionally absent — they are immutable
 * post-creation (lessons #0.0.3). Every field is nullable; the handler
 * only calls the matching domain method when the field is non-null.
 *
 * For a true "remove parent / clear path" operation the client sends
 * the field explicitly with `null` and the processor flips a separate
 * `clearParent` / `clearPath` boolean before dispatching the Command —
 * that keeps "field omitted" distinct from "field set to null".
 */
final class CatalogObjectPatchInput
{
    #[Groups(['object:patch'])]
    public ?bool $enabled = null;

    /**
     * One of {@see \App\Catalog\Domain\Entity\CatalogObject::STATUS_*}.
     * The handler resolves the target status against the
     * `object_editorial` state machine (ADR-0029) and applies the
     * matching transition; a target unreachable from the current
     * status is rejected with 409 Conflict.
     */
    #[Assert\Choice(choices: ['draft', 'review', 'published', 'archived'])]
    #[Groups(['object:patch'])]
    public ?string $status = null;

    /**
     * Re-parent this row. Pass `null` to remove the parent; the processor
     * will set `clearParent=true` because Merge-Patch JSON cannot express
     * "absent vs. null" otherwise.
     */
    #[Assert\Uuid(versions: [Assert\Uuid::V7_MONOTONIC])]
    #[Groups(['object:patch'])]
    public ?string $parentId = null;

    /**
     * `kind=category` ltree path (`'electronics.audio.headphones'`).
     * The Doctrine listener {@see \App\Catalog\Infrastructure\Doctrine\EventListener\CategoryPathValidator}
     * still rejects values for non-category rows.
     */
    #[Assert\Length(max: 4096)]
    #[Groups(['object:patch'])]
    public ?string $path = null;

    /**
     * Partial attribute update: any code present is upserted to an
     * ObjectValue with `provenance=Manual`. Codes absent in the payload
     * keep their existing values — Patch semantics only touch what the
     * client sends.
     *
     * @var array<string, mixed>|null
     */
    #[Groups(['object:patch'])]
    public ?array $attributes = null;

    /**
     * MODR-10 (#932) — optimistic-lock guard. The relation widget's
     * inline-edit panel reads `version` from the object payload and sends
     * it back here; the handler compares against the current row and
     * rejects with 409 Conflict on a mismatch ("stale data, refresh").
     */
    #[Assert\PositiveOrZero]
    #[Groups(['object:patch'])]
    public ?int $expectedVersion = null;
}
