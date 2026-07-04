<?php

declare(strict_types=1);

namespace App\Agent\Application\Tool;

use App\Export\Contracts\FeedAssistPort;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P7-01 (#1981) — feed generation as an ACTION: regenerate an
 * existing configured feed through the same FeedRun + RunFeedMessage
 * path as the admin's "Generuj teraz". Without a feed_id the tool
 * lists the tenant's feeds so the model can ask the user which one.
 */
final readonly class GenerateFeedTool implements AgentToolInterface, ProvidesQuickActionInterface
{
    public function __construct(
        private FeedAssistPort $feeds,
    ) {
    }

    public function name(): string
    {
        return 'generate_feed';
    }

    public function description(): string
    {
        return 'Regenerate an existing XML feed (async - the run streams progress on the feed Mercure topic). '
            .'Call WITHOUT feed_id first to list the configured feeds and confirm with the user which one to generate.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'feed_id' => ['type' => 'string', 'description' => 'UUID of the configured feed. Omit to list available feeds.'],
            ],
        ];
    }

    public function requiredPermission(): string
    {
        return 'integration.admin';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Action;
    }

    public function quickAction(): AgentQuickAction
    {
        return new AgentQuickAction(
            id: $this->name(),
            label: ['pl' => 'Eksport feed XML', 'en' => 'Export XML feed'],
            prompt: [
                'pl' => 'Wygeneruj feed XML [nazwa feedu]',
                'en' => 'Generate the XML feed [feed name]',
            ],
            priority: 30,
        );
    }

    public function execute(array $arguments, AgentToolContext $context): array
    {
        $feedId = $arguments['feed_id'] ?? null;

        if (!\is_string($feedId) || '' === $feedId) {
            return [
                'feeds' => $this->feeds->listFeeds(),
                'note' => 'Pick a feed with the user and call again with feed_id.',
            ];
        }

        if (!Uuid::isValid($feedId)) {
            return ['error' => 'feed_id must be a UUID - list the feeds first.'];
        }

        $runId = $this->feeds->regenerateFeed(Uuid::fromString($feedId));

        return [
            'feed_run_id' => $runId->toRfc4122(),
            'note' => 'Feed regeneration started (async). Progress streams on the feed run topic; the file lands at the feed URL when done.',
        ];
    }
}
