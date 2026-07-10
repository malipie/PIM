<?php

declare(strict_types=1);

namespace App\Agent\Application\Content;

use App\Shared\Application\TenantAwareMessage;
use Symfony\Component\Uid\Uuid;

/**
 * AICG-P6-03 (#2346, ADR-0030) — the async unit of a bulk content run.
 *
 * The dedicated bulk path (as opposed to the P5-03 agent-run loop) sizes
 * to hundreds of products: it does NOT spend the loop's 10-tool-call
 * budget, and the handler processes the ids memory-bounded (batch of 200
 * + EntityManager::clear, worker-memory rule §3.10). All proposals land
 * in the ONE batch the run will show for approval — never a catalog
 * write.
 *
 * @see BulkGenerateContentHandler
 */
final readonly class BulkGenerateContentMessage implements TenantAwareMessage
{
    /**
     * @param list<string> $productIds RFC-4122 UUIDs of the target objects
     * @param string       $toolName   the content write tool to run per product
     *                                 (generate_product_description | generate_seo_text)
     */
    public function __construct(
        public Uuid $runId,
        public Uuid $tenantId,
        public Uuid $batchId,
        public array $productIds,
        public string $toolName,
        public ?string $recipeId = null,
        public ?string $locale = null,
        public ?string $channel = null,
    ) {
    }

    public function tenantId(): Uuid
    {
        return $this->tenantId;
    }
}
