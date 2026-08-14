<?php

declare(strict_types=1);

namespace App\Identity\Application\SuperAdmin;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * RBAC-P3-014 (#677) — switches the request into the platform-level
 * Super Admin context per PRD §11 (privacy boundary + cross-tenant
 * bypass).
 *
 * Activating cross-tenant mode disables the Doctrine `TenantFilter` so
 * queries see every tenant's rows. The mode is **opt-in** per code
 * path — the entry point (admin controller / CLI command) calls
 * `useCrossTenantMode()` explicitly; outside that call site every
 * other code path still sees the filter enforced.
 *
 * The `restoreTenantScope()` call **must** sit in a finally block so a
 * thrown exception does not leak the cross-tenant view to subsequent
 * requests within the same FrankenPHP worker. The class returns the
 * previous filter state so nested activations compose correctly.
 *
 * Disabling the Doctrine filter is an application-layer concern only.
 * After AUD-002/W1-1 the runtime connects as `pim_app` (NOBYPASSRLS)
 * with FORCE ROW LEVEL SECURITY on every tenant-scoped table, so a
 * disabled `TenantFilter` does NOT by itself grant cross-tenant reads of
 * domain data — Postgres RLS still filters by the `app.current_tenant`
 * GUC. Crossing the RLS wall additionally requires the
 * `super_admin_bypass_<table>` policy, which is keyed on
 * `app.is_super_admin = 'true'` (set via {@see \App\Identity\Infrastructure\Doctrine\RlsContextListener}).
 * Setting that GUC is intentionally out of scope here (AUD-026 only
 * realigns the filter-name invariant); the RLS layer staying active for
 * `pim_app` is defence-in-depth, not a bug.
 *
 * Note that the break-glass entry points in MVP operate on `users` /
 * `roles` (auth tables) — `User` is {@see \App\Shared\Application\TenantAware},
 * not {@see \App\Shared\Application\TenantScoped}, so the Doctrine filter
 * never constrained those lookups; the filter toggle matters for any
 * `TenantScoped` read performed inside the cross-tenant closure.
 *
 * `superAdminId` is tracked on every cross-tenant write through the
 * audit log (`audit_logs.super_admin_id`, `cross_tenant_access=true`)
 * — set by {@see \App\Identity\Infrastructure\Audit\AuditLogListener}
 * when this context is active. Currently the listener inspects the
 * security principal type; a follow-up wires the special flag through
 * a request attribute so the listener does not need to reach into
 * this service.
 */
final class SuperAdminContext implements ResetInterface
{
    /**
     * Must match the filter name registered in
     * `config/packages/doctrine.yaml` (`doctrine.orm.filters.tenant`) and
     * toggled by {@see \App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator}.
     * AUD-026: this was `tenant_filter`, which silently no-op'd every
     * `enable()`/`disable()` here because that name is not registered —
     * the cross-tenant invariant was a lie.
     */
    public const string FILTER_NAME = 'tenant';

    private ?Uuid $activeSuperAdminId = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RlsBypass $rls,
    ) {
    }

    public function isActive(): bool
    {
        return null !== $this->activeSuperAdminId;
    }

    public function activeSuperAdminId(): ?Uuid
    {
        return $this->activeSuperAdminId;
    }

    /**
     * Opens cross-tenant mode for the duration of $callback, then
     * restores the previous filter state. Use this for one-shot
     * operations; for long-running commands prefer the explicit
     * `useCrossTenantMode()` / `restoreTenantScope()` pair so the
     * compiler can see the scope boundaries.
     *
     * @template T
     *
     * @param Closure(): T $callback
     *
     * @return T
     */
    public function runCrossTenant(Uuid $superAdminId, Closure $callback): mixed
    {
        $previous = $this->useCrossTenantMode($superAdminId);

        try {
            return $callback();
        } finally {
            $this->restoreTenantScope($previous);
        }
    }

    /**
     * Disables the tenant filter and stamps the active super admin id.
     * Returns the previous filter-enabled state so a finally block can
     * restore it.
     */
    public function useCrossTenantMode(Uuid $superAdminId): bool
    {
        $filters = $this->entityManager->getFilters();
        $previouslyEnabled = $filters->isEnabled(self::FILTER_NAME);

        if ($previouslyEnabled) {
            $filters->disable(self::FILTER_NAME);
        }

        $this->activeSuperAdminId = $superAdminId;

        // #2876 — the Doctrine filter is only the first isolation layer.
        // Postgres FORCE RLS is the second, and it reads `app.current_tenant`,
        // which the request listener pinned to the CALLER's tenant. Writing
        // another tenant's rows — which is the entire point of cross-tenant
        // mode — violates the policy, so provisioning a tenant died with
        // SQLSTATE 42501 after the tenant row was already inserted: created,
        // no owner invitation, an error on screen.
        //
        // It only ever worked where `app.current_tenant` happened to be
        // empty. `enableSuperAdminBypass()` was written for exactly this in
        // #677 and had no callers.
        $this->rls->enableSuperAdminBypass();

        return $previouslyEnabled;
    }

    public function restoreTenantScope(bool $reEnableFilter): void
    {
        $this->activeSuperAdminId = null;
        // Hand the privilege back immediately; `kernel.terminate` resets it
        // too, but the rest of THIS request must not keep it.
        $this->rls->disableSuperAdminBypass();

        if ($reEnableFilter) {
            $filters = $this->entityManager->getFilters();
            if (!$filters->isEnabled(self::FILTER_NAME)) {
                $filters->enable(self::FILTER_NAME);
            }
        }
    }

    /**
     * Symfony `kernel.reset` hook — fires at the end of every request in
     * FrankenPHP worker mode. Clears any cross-tenant mode that survived
     * the request (e.g. an exception that bypassed the `finally`, or a
     * long-running command that died mid-flight) so the privileged
     * super-admin context can never leak into the next request served by
     * the reused worker. The Doctrine filter itself is re-armed per request
     * by {@see \App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator},
     * so here we only have to drop the in-memory super-admin marker.
     */
    public function reset(): void
    {
        $this->activeSuperAdminId = null;
    }
}
