<?php

declare(strict_types=1);

namespace App\Agent\Application\Content;

/**
 * AICG-P2-01 (#2331, ADR-0030) — the grounding result the content tools
 * hand to the prompt builder: FACTS the model may use (and nothing
 * else — the anti-hallucination contract), the audit trail of which
 * codes produced them, and what is missing (input of the completeness
 * gate, AICG-P2-02).
 */
final readonly class ContentGrounding
{
    /**
     * @param array<string, array<string, mixed>>                $facts          attribute code → resolved value envelope
     * @param list<string>                                       $usedCodes      exactly the codes present in $facts —
     *                                                                           becomes provenance_meta.source_attributes
     * @param list<string>                                       $missingCodes   recipe source codes with no resolvable value
     * @param array<string, array<string, array<string, mixed>>> $siblingLocales code → locale → envelope (translation sources)
     * @param array<string, mixed>                               $channelContext channel scope handed to the prompt
     *                                                                           ({channel, published_locales}), [] without channel
     */
    public function __construct(
        public array $facts,
        public array $usedCodes,
        public array $missingCodes,
        public array $siblingLocales = [],
        public array $channelContext = [],
    ) {
    }

    public function hasFacts(): bool
    {
        return [] !== $this->facts;
    }
}
