<?php

declare(strict_types=1);

namespace App\Channel\Contracts;

use Symfony\Component\Uid\Uuid;

/**
 * XMLF-P3-03 (ADR-0023 §6.7) — read port for marketplace category IDs.
 *
 * A feed slot with `fmt: category` (Ceneo `<cat>`, Google
 * `g:google_product_category`) carries the RECEIVER's category identifier,
 * which lives on the channel category tree as
 * {@see \App\Channel\Domain\Entity\ChannelCategoryNode} `external_code`,
 * reached from a product's master categories through
 * {@see \App\Channel\Domain\Entity\ChannelCategoryNodeMapping}.
 *
 * Export/Feed consumes ONLY this contract (Deptrac: Export_Feed_Internals →
 * Channel_Contracts); master category references cross the boundary as bare
 * UUIDs (ADR-0015). Mirrors the ChannelResolverInterface seam.
 */
interface ChannelCategoryExternalCodeResolverInterface
{
    /**
     * Resolve marketplace category codes for master categories on one channel.
     *
     * For each master category id the first mapped channel node carrying a
     * non-empty `external_code` wins (mapping order). Unknown ids, unmapped
     * categories and nodes without an external code are simply absent from
     * the result — the caller decides the required/optional consequence.
     *
     * @param list<string> $masterCategoryIds RFC 4122 strings (bare cross-BC ids, ADR-0015)
     *
     * @return array<string, string> master category id → external_code
     */
    public function resolveExternalCodes(Uuid $channelId, array $masterCategoryIds): array;
}
