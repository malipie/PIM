<?php

declare(strict_types=1);

namespace App\Export\Feed\Presentation\Controller;

use App\Export\Feed\Application\Delivery\FeedCacheStorage;
use App\Export\Feed\Application\Delivery\FeedEtag;
use App\Export\Feed\Application\Delivery\FeedTokenService;
use App\Export\Feed\Domain\Entity\FeedProfile;
use App\Export\Feed\Domain\Repository\FeedProfileRepositoryInterface;
use App\Export\Feed\Domain\Repository\FeedPullStatsRepositoryInterface;
use App\Identity\Contracts\Attribute\NoPermissionRequired;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Repository\TenantRepositoryInterface;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Public feed URL — cache-and-serve (ADR-0023 §6.9, XMLF-P3-05 [SEC]).
 *
 * The URL an operator hands to Google/Ceneo/Meta:
 * `GET /api/feeds/pull/{tenantId}/{token}.xml`. It is PUBLIC_ACCESS (no
 * session) and NEVER generates — it only streams the last cached artifact
 * (anti-DoS: a flood cannot trigger a 50k regeneration).
 *
 * Tenant isolation is closed by construction, not by an RLS bypass: the
 * tenantId in the path establishes the RLS scope (GUC + TenantFilter) BEFORE
 * the feed lookup, and the 192-bit token is the credential. A token from tenant
 * A therefore can never resolve a feed of tenant B, and an unknown token/tenant
 * yields a flat 404 (no enumeration signal). This mirrors a presigned URL: the
 * tenantId is not a secret (it lives in the shared URL); the token is.
 */
final class FeedPullController
{
    public function __construct(
        private readonly FeedProfileRepositoryInterface $feeds,
        private readonly FeedTokenService $tokens,
        private readonly FeedCacheStorage $cache,
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantContext $tenantContext,
        private readonly TenantFilterConfigurator $tenantFilter,
        private readonly Connection $connection,
        private readonly FeedPullStatsRepositoryInterface $pullStats,
        private readonly RateLimiterFactoryInterface $feedPullLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/api/feeds/pull/{tenantId}/{token}.xml',
        name: 'pim_feeds_pull',
        methods: ['GET'],
        requirements: ['tenantId' => '[0-9a-fA-F-]{36}', 'token' => '[A-Za-z0-9_-]+'],
    )]
    #[NoPermissionRequired(reason: 'Public feed URL for external pullers (Google/Ceneo/Meta). The 192-bit URL token is the credential and the path tenantId scopes RLS before any lookup; no session/RBAC applies. Streams the cached artifact only — never generates.')]
    public function pull(string $tenantId, string $token, Request $request): Response
    {
        if (!Uuid::isValid($tenantId)) {
            throw $this->notFound();
        }
        $this->scopeToTenant(Uuid::fromString($tenantId));

        $feed = $this->feeds->findByTokenHash($this->tokens->hash($token));
        // Constant-time re-check + guards. A miss (wrong token, revoked token,
        // cross-tenant token) is indistinguishable from any other 404.
        if (null === $feed || !$this->tokens->matches($feed, $token)) {
            throw $this->notFound();
        }

        // Per-token budget (one active token per feed → keyed by feed id).
        // After token verification so unauthenticated probes cannot exhaust a
        // feed's budget, before any storage read so a hammering loop cannot
        // saturate MinIO bandwidth (XMLF-P3-06 [SEC]).
        $this->enforceRateLimit($feed);

        $key = $feed->getCachedFilePath();
        if (null === $key || !$this->cache->exists($key)) {
            // Never regenerated (or cache swept) — do not generate on pull.
            throw $this->notFound();
        }

        // Both 200 and 304 count: a conditional revalidation still proves the
        // crawler polls the URL (that is the signal the monitor cares about).
        $this->recordPull($feed);

        $etag = FeedEtag::forCache($feed->getCachedAt(), $feed->getCachedFileSize(), $feed->getCachedItemCount());
        if ($this->matchesIfNoneMatch($request, $etag)) {
            return new Response(status: Response::HTTP_NOT_MODIFIED, headers: $this->cacheHeaders($etag));
        }

        return $this->stream($feed, $key, $etag);
    }

    private function enforceRateLimit(FeedProfile $feed): void
    {
        $limiter = $this->feedPullLimiter->create('feed-pull:'.$feed->getId()->toRfc4122());
        $consumed = $limiter->consume();
        if ($consumed->isAccepted()) {
            return;
        }

        $secondsUntilReset = max(1, $consumed->getRetryAfter()->getTimestamp() - time());

        throw new TooManyRequestsHttpException(
            $secondsUntilReset,
            'Feed pull rate limit exceeded. Try again later.',
            null,
            0,
            ['Retry-After' => (string) $secondsUntilReset],
        );
    }

    private function recordPull(FeedProfile $feed): void
    {
        try {
            $this->pullStats->record($feed, new DateTimeImmutable());
        } catch (Throwable $error) {
            // Telemetry must never take the public URL down — serve anyway.
            // Log by feed id only; the token never reaches the telemetry path.
            $this->logger->warning('Feed pull telemetry failed; serving the feed anyway.', [
                'feed_id' => $feed->getId()->toRfc4122(),
                'error' => $error->getMessage(),
            ]);
        }
    }

    private function scopeToTenant(Uuid $tenantId): void
    {
        $tenant = $this->tenants->findById($tenantId);
        if (!$tenant instanceof Tenant) {
            throw $this->notFound();
        }
        // Establish the RLS scope from the URL before any TenantScoped query:
        // the Postgres GUC (row-level security) + the Doctrine TenantFilter.
        // tenant-safe: infrastructure (establishes the tenant_id RLS policies read from the URL path; this IS the tenant boundary the token then gates, not a bypass — mirrors RlsContextListener)
        $this->connection->executeStatement(
            "SELECT set_config('app.current_tenant', :tenant, false)",
            ['tenant' => $tenantId->toRfc4122()],
        );
        $this->tenantContext->set($tenant);
        // Re-apply the Doctrine tenant filter to the context we just set.
        // Without this the filter keeps whatever the previous request on this
        // worker configured — an enabled filter for tenant X would AND
        // `tenant_id = X` into the token lookup below and turn a perfectly
        // valid pull for tenant Y into a 404 (found by the P6-02 suite).
        $this->tenantFilter->apply();
    }

    private function stream(FeedProfile $feed, string $key, string $etag): StreamedResponse
    {
        $headers = $this->cacheHeaders($etag);
        $headers['Content-Type'] = 'application/xml; charset=UTF-8';
        $size = $feed->getCachedFileSize();
        if (null !== $size) {
            $headers['Content-Length'] = (string) $size;
        }

        $storage = $this->cache;

        return new StreamedResponse(static function () use ($storage, $key): void {
            $stream = $storage->read($key);
            $out = fopen('php://output', 'w');
            if (false !== $out) {
                stream_copy_to_stream($stream, $out);
                fclose($out);
            }
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }, Response::HTTP_OK, $headers);
    }

    /**
     * @return array<string, string>
     */
    private function cacheHeaders(string $etag): array
    {
        return [
            'ETag' => $etag,
            // Pullers poll on a schedule; allow a short shared cache + revalidation.
            'Cache-Control' => 'public, max-age=300, must-revalidate',
            'Vary' => 'Accept-Encoding',
        ];
    }

    private function matchesIfNoneMatch(Request $request, string $etag): bool
    {
        $header = $request->headers->get('If-None-Match');
        if (null === $header) {
            return false;
        }
        foreach (array_map(trim(...), explode(',', $header)) as $candidate) {
            if ($candidate === $etag || $candidate === 'W/'.$etag) {
                return true;
            }
        }

        return false;
    }

    private function notFound(): NotFoundHttpException
    {
        return new NotFoundHttpException('Feed not found.');
    }
}
