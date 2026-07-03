<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\Application\Tool\AgentToolContext;
use App\Agent\Application\Tool\PublishToChannelTool;
use App\Agent\Application\Tool\ToolKind;
use App\Identity\Contracts\Policy\UserScopedPermissionCheckerInterface;
use App\Integration\Application\UnavailableChannelPublisher;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT-P7-02 (#1982) — the engine-gated publish tool: the pre-engine
 * default answers available=false with an honest reason + working
 * alternatives, and a channel outside the user's scope (§6) refuses
 * BEFORE the engine is asked.
 */
final class PublishToChannelToolTest extends TestCase
{
    #[Test]
    public function preEngineDefaultAnswersHonestUnavailability(): void
    {
        $tool = new PublishToChannelTool(new UnavailableChannelPublisher(), $this->scopes(true));
        self::assertSame(ToolKind::Action, $tool->kind());
        self::assertSame('publications.publish_unpublish', $tool->requiredPermission());

        $result = $tool->execute([
            'channel_code' => 'shopify',
            'object_ids' => [Uuid::v7()->toRfc4122()],
        ], $this->context());

        self::assertFalse($result['available']);
        self::assertIsString($result['reason']);
        self::assertStringContainsString('Faza 1', $result['reason']);
        self::assertStringContainsString('trigger_export', $result['reason'], 'the model must be able to offer the working alternative');
    }

    #[Test]
    public function channelOutsideUserScopeRefusesBeforeTheEngine(): void
    {
        $engine = new class implements \App\Integration\Contracts\ChannelPublishPort {
            public bool $asked = false;

            public function publishableChannels(): array
            {
                return [];
            }

            public function publishSelection(string $channelCode, array $objectIds): array
            {
                $this->asked = true;

                return ['available' => true, 'queued' => \count($objectIds)];
            }
        };

        $tool = new PublishToChannelTool($engine, $this->scopes(false));
        $result = $tool->execute([
            'channel_code' => 'shopify',
            'object_ids' => [Uuid::v7()->toRfc4122()],
        ], $this->context());

        self::assertFalse($result['available']);
        self::assertIsString($result['reason']);
        self::assertStringContainsString('scope', $result['reason']);
        self::assertFalse($engine->asked, 'scope must refuse BEFORE the engine is asked');
    }

    #[Test]
    public function emptySelectionIsAnError(): void
    {
        $tool = new PublishToChannelTool(new UnavailableChannelPublisher(), $this->scopes(true));

        $result = $tool->execute(['channel_code' => 'shopify', 'object_ids' => []], $this->context());

        self::assertArrayHasKey('error', $result);
    }

    private function scopes(bool $allows): UserScopedPermissionCheckerInterface
    {
        return new class($allows) implements UserScopedPermissionCheckerInterface {
            public function __construct(private readonly bool $allows)
            {
            }

            public function canViewAttribute(Uuid $userId, Uuid $attributeId): bool
            {
                return $this->allows;
            }

            public function canEditAttribute(Uuid $userId, Uuid $attributeId): bool
            {
                return $this->allows;
            }

            public function canEditLocale(Uuid $userId, string $locale): bool
            {
                return $this->allows;
            }

            public function canEditChannel(Uuid $userId, string $channel): bool
            {
                return $this->allows;
            }
        };
    }

    private function context(): AgentToolContext
    {
        return new AgentToolContext(Uuid::v7(), new Tenant('alpha', 'Alpha'), []);
    }
}
