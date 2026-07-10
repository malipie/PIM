<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Command;

use Symfony\Component\Uid\Uuid;

/**
 * AICG-P3-01 (#2334, ADR-0030) — the ONLY write path of generated text:
 * one proposed value materialized into `pending_changes` (diff = plan),
 * committed to the catalog exclusively after human approval through the
 * existing bulk-path. Nothing here touches `object_values` or the
 * `attributes_indexed` cache directly.
 *
 * `meta` carries the content audit trail the batch commit later stamps
 * into `provenance_meta` (docs/api/jsonb-schemas.md §5): agent_run_id,
 * intent, source_attributes, recipe_id.
 */
interface ContentValuePort
{
    /**
     * @param array<string, mixed> $meta
     */
    public function materializeGeneratedValue(
        Uuid $batchId,
        Uuid $userId,
        Uuid $objectId,
        string $attributeCode,
        string $generatedText,
        ?string $locale = null,
        ?string $channel = null,
        array $meta = [],
    ): ContentValueProposal;
}
