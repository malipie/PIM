<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use Anthropic\Client;
use App\Agent\Domain\Exception\AgentUnavailableException;
use App\Agent\Infrastructure\Anthropic\AgentModelSelector;
use App\Agent\Infrastructure\Anthropic\AnthropicClientFactory;
use App\Identity\Contracts\Byok\ByokKeyResolverInterface;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AGENT-P0-06 (#1949) — BYOK-backed client factory: no key -> agent
 * unavailable (never a fallback to someone else's key), key -> a
 * configured SDK client; the secret never leaks into the exception.
 */
final class AnthropicClientFactoryTest extends TestCase
{
    private const string TEST_KEY = 'sk-ant-test-0123456789abcdef';

    #[Test]
    public function missingByokKeyThrowsAgentUnavailable(): void
    {
        $factory = new AnthropicClientFactory($this->resolver(null), maxRetries: 4, timeoutSeconds: 120.0);

        $this->expectException(AgentUnavailableException::class);
        $this->expectExceptionMessageMatches('/no active Anthropic BYOK key/');

        $factory->forTenant(new Tenant('alpha', 'Alpha'));
    }

    #[Test]
    public function disabledKeyResolvedAsEmptyStringAlsoThrows(): void
    {
        $factory = new AnthropicClientFactory($this->resolver(''), maxRetries: 4, timeoutSeconds: 120.0);

        $this->expectException(AgentUnavailableException::class);

        $factory->forTenant(new Tenant('alpha', 'Alpha'));
    }

    #[Test]
    public function activeKeyBuildsClient(): void
    {
        $factory = new AnthropicClientFactory($this->resolver(self::TEST_KEY), maxRetries: 4, timeoutSeconds: 120.0);

        $client = $factory->forTenant(new Tenant('alpha', 'Alpha'));

        self::assertInstanceOf(Client::class, $client);
    }

    #[Test]
    public function unavailableMessageNeverContainsKeyMaterial(): void
    {
        $factory = new AnthropicClientFactory($this->resolver(null), maxRetries: 4, timeoutSeconds: 120.0);

        try {
            $factory->forTenant(new Tenant('alpha', 'Alpha'));
            self::fail('Expected AgentUnavailableException');
        } catch (AgentUnavailableException $e) {
            self::assertStringNotContainsString('sk-ant', $e->getMessage());
        }
    }

    #[Test]
    public function modelSelectorRoutesSchemaKindToOpusTier(): void
    {
        $selector = new AgentModelSelector(
            defaultModel: 'claude-sonnet-4-6',
            schemaModel: 'claude-opus-4-8',
        );

        self::assertSame('claude-opus-4-8', $selector->modelForKind('schema'));
        self::assertSame('claude-sonnet-4-6', $selector->modelForKind('read'));
        self::assertSame('claude-sonnet-4-6', $selector->modelForKind('write'));
        self::assertSame('claude-sonnet-4-6', $selector->modelForKind('action'));
    }

    private function resolver(?string $key): ByokKeyResolverInterface
    {
        return new class($key) implements ByokKeyResolverInterface {
            public function __construct(private readonly ?string $key)
            {
            }

            public function resolveKey(Tenant $tenant): ?string
            {
                return $this->key;
            }
        };
    }
}
