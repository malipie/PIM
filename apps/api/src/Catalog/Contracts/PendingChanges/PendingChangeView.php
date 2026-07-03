<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\PendingChanges;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Read model of a materialized pending change, returned by the port so
 * consumers (the agent BC, future producers, the approval inbox) never
 * touch the Catalog domain entity.
 */
final readonly class PendingChangeView
{
    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, mixed>|null $meta
     */
    public function __construct(
        public Uuid $id,
        public Uuid $batchId,
        public string $provenance,
        public PendingChangeType $changeType,
        public PendingChangeStatus $status,
        public ?Uuid $targetObjectId,
        public ?string $attributeCode,
        public ?string $scopeLocale,
        public ?string $scopeChannel,
        public ?array $before,
        public ?array $after,
        public ?array $meta,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $decidedAt,
        // #2154 — the target object's SKU + display name, so the approval
        // diff reads "SKU · Name · attr: before -> after" instead of a bare
        // number. Populated by list reads (the inbox); null on the streaming
        // commit path, which does not need them.
        public ?string $targetObjectCode = null,
        public ?string $targetObjectName = null,
    ) {
    }
}
