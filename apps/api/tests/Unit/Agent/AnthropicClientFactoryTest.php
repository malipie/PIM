<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use Anthropic\Client;
use App\Agent\Domain\Exception\AgentUnavailableException;
use App\Agent\Infrastructure\Anthropic\AgentModelSelector;
use App\Agent\Infrastructure\Anthropic\AnthropicClientBuilderInterface;
use App\Agent\Infrastructure\Anthropic\AnthropicClientFactory;
use App\Agent\Infrastructure\Anthropic\SdkAnthropicClientBuilder;
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
        $factory = $this->factory(null);

        $this->expectException(AgentUnavailableException::class);
        $this->expectExceptionMessageMatches('/no active Anthropic BYOK key/');

        $factory->forTenant(new Tenant('alpha', 'Alpha'));
    }

    #[Test]
    public function disabledKeyResolvedAsEmptyStringAlsoThrows(): void
    {
        $factory = $this->factory('');

        $this->expectException(AgentUnavailableException::class);

        $factory->forTenant(new Tenant('alpha', 'Alpha'));
    }

    #[Test]
    public function activeKeyBuildsClientWithConfiguredRetryAndTimeout(): void
    {
        $client = $this->createStub(Client::class);
        $builder = new class(self::TEST_KEY, $client) implements AnthropicClientBuilderInterface {
            public bool $receivedExpectedKey = false;

            /** @var array{maxRetries: int, timeout: float}|null */
            public ?array $receivedRequestOptions = null;

            public function __construct(
                private readonly string $expectedKey,
                private readonly Client $client,
            ) {
            }

            /**
             * @param array{maxRetries: int, timeout: float} $requestOptions
             */
            public function build(string $apiKey, array $requestOptions): Client
            {
                $this->receivedExpectedKey = hash_equals($this->expectedKey, $apiKey);
                $this->receivedRequestOptions = $requestOptions;

                return $this->client;
            }
        };
        $factory = new AnthropicClientFactory(
            $this->resolver(self::TEST_KEY),
            $builder,
            maxRetries: 4,
            timeoutSeconds: 120.0,
        );

        $builtClient = $factory->forTenant(new Tenant('alpha', 'Alpha'));

        self::assertSame($client, $builtClient);
        self::assertTrue($builder->receivedExpectedKey, 'Builder received an unexpected API key.');
        self::assertNotNull($builder->receivedRequestOptions);
        self::assertSame(4, $builder->receivedRequestOptions['maxRetries']);
        self::assertSame(120.0, $builder->receivedRequestOptions['timeout']);
    }

    #[Test]
    public function unavailableMessageNeverContainsKeyMaterial(): void
    {
        $factory = $this->factory(null);

        try {
            $factory->forTenant(new Tenant('alpha', 'Alpha'));
            self::fail('Expected AgentUnavailableException');
        } catch (AgentUnavailableException $e) {
            self::assertStringNotContainsString('sk-ant', $e->getMessage());
        }
    }

    #[Test]
    public function sdkBuilderConstructsClientWithoutRenderingKeyMaterial(): void
    {
        $client = new SdkAnthropicClientBuilder()->build(
            self::TEST_KEY,
            ['maxRetries' => 4, 'timeout' => 120.0],
        );

        self::assertTrue(hash_equals(self::TEST_KEY, $client->apiKey));
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

            public function hasActiveKey(Tenant $tenant): bool
            {
                return null !== $this->key && '' !== $this->key;
            }
        };
    }

    private function factory(?string $key): AnthropicClientFactory
    {
        return new AnthropicClientFactory(
            $this->resolver($key),
            $this->createStub(AnthropicClientBuilderInterface::class),
            maxRetries: 4,
            timeoutSeconds: 120.0,
        );
    }
}
