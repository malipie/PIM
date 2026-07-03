<?php

declare(strict_types=1);

namespace App\Export\Contracts;

use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P7-01 (#1981) — the agent's window into the XML feed
 * configurator (UC3, boundary §5.6: the agent asks the ENGINE for a
 * structure preview on a data sample - it never builds its own
 * serializer):
 *
 *   - suggestStructure: evaluate a built-in template (google_shopping /
 *     ceneo / meta / custom) on a sample of real data and return the
 *     slot list + default source mapping + the engine's preview XML and
 *     health report (missing required slots per SKU);
 *   - listFeeds / regenerateFeed: feed generation as an ACTION - the
 *     same FeedRun + RunFeedMessage path the admin's "Generuj teraz"
 *     uses.
 */
interface FeedAssistPort
{
    /**
     * @param array<string, mixed> $filterDsl sample selector ([] = whole catalog)
     *
     * @return array{template: string, root: string, slots: list<array{slot: string, source: string}>, sample_count: int, xml: string, health: list<array{sku: string|null, slot: string, level: string, message: string}>}
     *
     * @throws InvalidArgumentException on unknown template kind / object type
     */
    public function suggestStructure(string $templateKind, string $objectTypeCode, array $filterDsl, int $sampleLimit = 5): array;

    /**
     * @return list<array{id: string, code: string, name: string}>
     */
    public function listFeeds(): array;

    /**
     * @return Uuid the created feed run id
     *
     * @throws InvalidArgumentException on unknown feed
     */
    public function regenerateFeed(Uuid $feedId): Uuid;
}
