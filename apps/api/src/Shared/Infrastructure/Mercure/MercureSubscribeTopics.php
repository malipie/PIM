<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mercure;

use Symfony\Component\Uid\Uuid;

/**
 * AUD-001 (#1573) — single source of truth for Mercure topic naming and
 * for the per-tenant `mercure.subscribe` claim.
 *
 * Every domain topic is prefixed with `tenant/{tenantId}/` so the hub can
 * isolate tenants: a private update on `…/tenant/A/objects` is only
 * delivered to a subscriber whose JWT authorises that exact topic, and
 * the authorization endpoint mints a cookie scoped to the caller's
 * tenant alone ({@see forTenant()}).
 *
 * Before this class the publishers used un-prefixed topics
 * (`{base}/objects`, `{base}/imports/{id}`, …) and the hub ran in
 * `anonymous` mode, so any client — even one with no account — received
 * every tenant's real-time catalog / import / export / permission
 * events. The tenant prefix + `private: true` on every Update + a
 * subscriber-JWT requirement on the hub together close that leak.
 *
 * The subscribe claim uses RFC 6570 URI templates (`{id}`) which the
 * dunglas/mercure hub matches per path segment — `…/tenant/A/objects/{id}`
 * authorises any single object row under tenant A but nothing outside the
 * `tenant/A/` prefix.
 */
final class MercureSubscribeTopics
{
    public const string SEGMENT_TENANT = 'tenant';

    /**
     * Tenant-scoped prefix every topic family hangs off, e.g.
     * `https://pim.localhost/tenant/<uuid>`.
     */
    public static function tenantPrefix(Uuid $tenantId, string $base): string
    {
        return \sprintf('%s/%s/%s', rtrim($base, '/'), self::SEGMENT_TENANT, $tenantId->toRfc4122());
    }

    public static function objectRow(Uuid $tenantId, string $base, string $objectId): string
    {
        return self::tenantPrefix($tenantId, $base).'/objects/'.$objectId;
    }

    public static function objectsBroadcast(Uuid $tenantId, string $base): string
    {
        return self::tenantPrefix($tenantId, $base).'/objects';
    }

    public static function importSession(Uuid $tenantId, string $base, string $sessionId): string
    {
        return self::tenantPrefix($tenantId, $base).'/imports/'.$sessionId;
    }

    public static function importUser(Uuid $tenantId, string $base, string $userId): string
    {
        return self::tenantPrefix($tenantId, $base).'/imports/user/'.$userId;
    }

    public static function exportSession(Uuid $tenantId, string $base, string $sessionId): string
    {
        return self::tenantPrefix($tenantId, $base).'/exports/'.$sessionId;
    }

    public static function exportUser(Uuid $tenantId, string $base, string $userId): string
    {
        return self::tenantPrefix($tenantId, $base).'/exports/'.$userId;
    }

    /**
     * WFL-P2-02 — per-user persistent notification stream (bell badge +
     * live prepend). Tenant-prefixed like every subscribe topic.
     */
    public static function userNotifications(Uuid $tenantId, string $base, string $userId): string
    {
        return self::tenantPrefix($tenantId, $base).'/users/'.$userId.'/notifications';
    }

    /**
     * Live regeneration progress of one feed (XMLF-P4-02) — every run of the
     * feed publishes progress + status here; the monitor screen subscribes
     * per feed, so a new run appears on the already-open detail view.
     */
    public static function feedRuns(Uuid $tenantId, string $base, string $feedId): string
    {
        return self::tenantPrefix($tenantId, $base).'/feeds/'.$feedId.'/runs';
    }

    /**
     * Live regeneration progress of one PDF catalog (CPDF-P3-01) — every run of
     * the catalog publishes progress + status here; the monitor screen
     * subscribes per catalog, so a new run appears on the already-open detail
     * view.
     */
    public static function catalogRuns(Uuid $tenantId, string $base, string $catalogId): string
    {
        return self::tenantPrefix($tenantId, $base).'/catalogs/'.$catalogId.'/runs';
    }

    /**
     * Live phases of one agent run (AGENT-P1-05 #1957): planning /
     * tool-call / materializing / awaiting_approval / committing / done
     * / error / rolled_back — the chat panel + inbox subscribe per run.
     */
    public static function agentRun(Uuid $tenantId, string $base, string $runId): string
    {
        return self::tenantPrefix($tenantId, $base).'/agent-runs/'.$runId;
    }

    /**
     * Per-user broadcast (AGENT-P1-05): any of the user's runs changing
     * state refreshes their history/inbox list.
     */
    public static function agentUser(Uuid $tenantId, string $base, string $userId): string
    {
        return self::tenantPrefix($tenantId, $base).'/agent-runs/user/'.$userId;
    }

    public static function identityUser(Uuid $tenantId, string $base, string $userId): string
    {
        return self::tenantPrefix($tenantId, $base).'/identity/user/'.$userId;
    }

    public static function identityTenant(Uuid $tenantId, string $base): string
    {
        return self::tenantPrefix($tenantId, $base).'/identity/tenant/'.$tenantId->toRfc4122();
    }

    /**
     * URI-template subscribe claim for the caller's tenant — a closed set,
     * every entry pinned to the `tenant/{tenantId}/` prefix. Used to mint
     * the `mercureAuthorization` cookie. There is deliberately NO global
     * (prefix-less) topic and NO other tenant's prefix in the list.
     *
     * @return list<string>
     */
    public static function forTenant(Uuid $tenantId, string $base): array
    {
        $prefix = self::tenantPrefix($tenantId, $base);

        return [
            // Catalog live updates (bell + row editing).
            $prefix.'/objects',
            $prefix.'/objects/{id}',
            // Import progress (list + detail).
            $prefix.'/imports/{id}',
            $prefix.'/imports/user/{id}',
            // Export progress (list + detail).
            $prefix.'/exports/{id}',
            // Feed regeneration progress (XMLF-P4-02, per-feed run stream).
            $prefix.'/feeds/{id}/runs',
            // Catalog regeneration progress (CPDF-P3-01, per-catalog run stream).
            $prefix.'/catalogs/{id}/runs',
            // Agent run phases + per-user history refresh (AGENT-P1-05).
            $prefix.'/agent-runs/{id}',
            $prefix.'/agent-runs/user/{id}',
            // Permission invalidation (per-user + tenant-wide).
            $prefix.'/identity/user/{id}',
            $prefix.'/identity/tenant/{id}',
        ];
    }
}
