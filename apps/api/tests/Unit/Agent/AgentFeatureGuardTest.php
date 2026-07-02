<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\Application\AgentFeatureGuard;
use App\Agent\Domain\Exception\AgentUnavailableException;
use App\Identity\Contracts\Byok\ByokKeyResolverInterface;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AGENT-P0-08 (#1951) — the guard refuses when the global flag is off
 * OR the tenant has no active BYOK key; refusal is a 403 problem (not a
 * 500). Tenant without a key -> agent off is a deliberate state.
 */
final class AgentFeatureGuardTest extends TestCase
{
    #[Test]
    public function globalFlagOffRefusesEvenWithActiveKey(): void
    {
        $guard = new AgentFeatureGuard($this->keys(true), agentEnabled: false);

        self::assertFalse($guard->isEnabled($this->tenant()));

        $this->expectException(AgentUnavailableException::class);
        $this->expectExceptionMessageMatches('/feature flag is off/');
        $guard->assertEnabled($this->tenant());
    }

    #[Test]
    public function tenantWithoutActiveKeyRefusesWith403(): void
    {
        $guard = new AgentFeatureGuard($this->keys(false), agentEnabled: true);

        self::assertFalse($guard->isEnabled($this->tenant()));

        try {
            $guard->assertEnabled($this->tenant());
            self::fail('Expected AgentUnavailableException');
        } catch (AgentUnavailableException $e) {
            // HttpExceptionInterface -> shared RFC 7807 listener renders 403.
            self::assertSame(403, $e->getStatusCode());
            self::assertStringContainsString('BYOK', $e->getMessage());
        }
    }

    #[Test]
    public function flagOnAndActiveKeyPasses(): void
    {
        $guard = new AgentFeatureGuard($this->keys(true), agentEnabled: true);

        self::assertTrue($guard->isEnabled($this->tenant()));
        $guard->assertEnabled($this->tenant());
        $this->addToAssertionCount(1);
    }

    private function keys(bool $active): ByokKeyResolverInterface
    {
        return new class($active) implements ByokKeyResolverInterface {
            public function __construct(private readonly bool $active)
            {
            }

            public function resolveKey(Tenant $tenant): ?string
            {
                return $this->active ? 'sk-ant-test' : null;
            }

            public function hasActiveKey(Tenant $tenant): bool
            {
                return $this->active;
            }
        };
    }

    private function tenant(): Tenant
    {
        return new Tenant('alpha', 'Alpha Tenant');
    }
}
