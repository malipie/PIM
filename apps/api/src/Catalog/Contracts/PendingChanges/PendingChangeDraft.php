<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\PendingChanges;

use Symfony\Component\Uid\Uuid;

/**
 * One proposed change handed to {@see PendingChangesPort::materialize()}.
 *
 * For {@see PendingChangeType::Value} drafts, `before`/`after` MUST be
 * value envelopes per docs/api/jsonb-schemas.md (`['value' => ...]`),
 * or null (no previous value / value removal).
 *
 * `meta` is free-form producer context (agent: run id, model, intent).
 */
final readonly class PendingChangeDraft
{
    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, mixed>|null $meta
     */
    public function __construct(
        public PendingChangeType $changeType,
        public ?Uuid $targetObjectId = null,
        public ?string $attributeCode = null,
        public ?string $scopeLocale = null,
        public ?string $scopeChannel = null,
        public ?array $before = null,
        public ?array $after = null,
        public ?array $meta = null,
    ) {
    }
}
