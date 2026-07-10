<?php

declare(strict_types=1);

namespace App\Agent\Application\Content;

use App\Agent\Domain\Entity\ContentRecipe;
use App\Catalog\Contracts\Query\ObjectFacts;
use App\Catalog\Contracts\Query\ObjectFactsPort;
use App\Channel\Contracts\ChannelPublicationResolverInterface;
use App\Shared\Domain\Tenant;
use Symfony\Component\Uid\Uuid;

/**
 * AICG-P2-01 (#2331, ADR-0030) — the "based on WHAT" core of content
 * generation (plan §3a): collects the FACTS a content tool grounds the
 * model in — the recipe's source attributes resolved for the requested
 * locale/channel reading, sibling-locale values as translation sources,
 * and the channel publication context.
 *
 * Deterministic and read-only: facts = recipe.sourceAttributes ∩ what
 * `attributes_indexed` resolves (via the Catalog Contracts port — this
 * BC never touches Catalog internals); with a channel, the set is
 * additionally narrowed to the publication profile allow-list
 * (ADR-0018 — a fact the channel never publishes must not shape its
 * copy). Missing codes are reported, not invented — the completeness
 * gate (AICG-P2-02) decides whether generation may proceed.
 */
final readonly class ContentGroundingService
{
    public function __construct(
        private ObjectFactsPort $facts,
        private ChannelPublicationResolverInterface $publication,
    ) {
    }

    public function ground(
        Uuid $productId,
        ContentRecipe $recipe,
        Tenant $tenant,
        ?string $locale = null,
        ?string $channel = null,
    ): ContentGrounding {
        $sourceCodes = $recipe->getSourceAttributes();
        $channelContext = [];

        $facts = $this->facts->facts($productId, $sourceCodes, $locale, $channel);

        if (null !== $channel && '' !== $channel) {
            $published = $this->publication->resolvePublishedCodes($channel, $facts->objectTypeId, $tenant);
            $channelContext = [
                'channel' => $channel,
                'published_locales' => $this->publication->resolvePublishedLocales($channel, $tenant),
            ];
            if (null !== $published) {
                // null = publish-all; a list narrows the fact set — codes the
                // channel never publishes must not shape its copy. Dropped
                // codes are NOT "missing" (the product may well carry them);
                // they are out of scope for this reading.
                $allowed = array_flip($published);
                $facts = new ObjectFacts(
                    objectId: $facts->objectId,
                    objectTypeId: $facts->objectTypeId,
                    values: array_intersect_key($facts->values, $allowed),
                    missingCodes: array_values(array_intersect($facts->missingCodes, $published)),
                    siblingLocales: array_intersect_key($facts->siblingLocales, $allowed),
                );
            }
        }

        return new ContentGrounding(
            facts: $facts->values,
            usedCodes: $facts->presentCodes(),
            missingCodes: $facts->missingCodes,
            siblingLocales: $facts->siblingLocales,
            channelContext: $channelContext,
        );
    }
}
