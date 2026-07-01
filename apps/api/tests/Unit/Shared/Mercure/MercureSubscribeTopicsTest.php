<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Mercure;

use App\Shared\Infrastructure\Mercure\MercureSubscribeTopics;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * AUD-001 (#1573) — the subscribe-claim builder is the single chokepoint
 * that decides which Mercure topics a logged-in caller may listen on.
 *
 * The regression this pins: before the fix every caller (even anonymous)
 * could subscribe to the GLOBAL `objects` / `imports` / `exports` /
 * `identity` topics, so tenant A received tenant B's real-time events.
 *
 * The contract now: the claim is a closed set of URI templates, every
 * one prefixed with `tenant/{thisTenant}/…`. There must be NO global
 * (prefix-less) topic and NO other tenant's prefix in the list — that is
 * what stops the hub from delivering a private cross-tenant update.
 */
final class MercureSubscribeTopicsTest extends TestCase
{
    private const string BASE = 'https://pim.localhost';
    private const string TENANT_A = '019ddb19-1111-7000-8000-aaaaaaaaaaaa';
    private const string TENANT_B = '019ddb19-2222-7000-8000-bbbbbbbbbbbb';

    #[Test]
    public function everyTopicIsScopedToTheGivenTenantPrefix(): void
    {
        $topics = MercureSubscribeTopics::forTenant(Uuid::fromString(self::TENANT_A), self::BASE);

        self::assertNotEmpty($topics);
        $prefix = self::BASE.'/tenant/'.self::TENANT_A.'/';
        foreach ($topics as $topic) {
            self::assertStringStartsWith(
                $prefix,
                $topic,
                \sprintf('Subscribe topic "%s" escapes the tenant scope %s', $topic, $prefix),
            );
        }
    }

    #[Test]
    public function claimContainsNoGlobalPrefixlessTopic(): void
    {
        $topics = MercureSubscribeTopics::forTenant(Uuid::fromString(self::TENANT_A), self::BASE);

        // The exact strings that leaked before the fix.
        $leakedGlobals = [
            self::BASE.'/objects',
            '/objects',
            self::BASE.'/imports/{id}',
            self::BASE.'/exports/{id}',
            self::BASE.'/identity/user/{id}',
        ];
        foreach ($leakedGlobals as $global) {
            self::assertNotContains(
                $global,
                $topics,
                \sprintf('Global topic "%s" must never be in a tenant subscribe claim.', $global),
            );
        }
    }

    #[Test]
    public function tenantAClaimDoesNotAuthoriseTenantBTopics(): void
    {
        $topics = MercureSubscribeTopics::forTenant(Uuid::fromString(self::TENANT_A), self::BASE);

        $otherPrefix = self::BASE.'/tenant/'.self::TENANT_B.'/';
        foreach ($topics as $topic) {
            self::assertFalse(
                str_starts_with($topic, $otherPrefix),
                'Tenant A must not be granted any tenant B topic.',
            );
        }
    }

    #[Test]
    public function claimCoversObjectsImportsExportsAndIdentityFamilies(): void
    {
        $topics = MercureSubscribeTopics::forTenant(Uuid::fromString(self::TENANT_A), self::BASE);
        $joined = implode("\n", $topics);

        // Each live SSE consumer family must be reachable for the tenant.
        self::assertStringContainsString('/objects', $joined);
        self::assertStringContainsString('/imports', $joined);
        self::assertStringContainsString('/exports', $joined);
        self::assertStringContainsString('/identity', $joined);
        // XMLF-P4-02 — feed regeneration progress family.
        self::assertStringContainsString('/feeds', $joined);
    }

    #[Test]
    public function feedRunsTopicIsTenantScopedAndCoveredByTheClaim(): void
    {
        $tenantId = Uuid::fromString(self::TENANT_A);
        $feedId = '019f1f42-b277-750c-919e-83f65bf9bf54';

        $topic = MercureSubscribeTopics::feedRuns($tenantId, self::BASE, $feedId);

        self::assertSame(
            self::BASE.'/tenant/'.self::TENANT_A.'/feeds/'.$feedId.'/runs',
            $topic,
        );
        // The URI-template claim (`/feeds/{id}/runs`) must authorise exactly
        // this topic shape — one path segment for the feed id.
        self::assertContains(
            self::BASE.'/tenant/'.self::TENANT_A.'/feeds/{id}/runs',
            MercureSubscribeTopics::forTenant($tenantId, self::BASE),
        );
    }
}
