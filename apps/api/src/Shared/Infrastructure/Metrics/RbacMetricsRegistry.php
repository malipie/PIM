<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Metrics;

/**
 * RBAC-P6-009 (#721) — in-memory Prometheus registry for RBAC-specific
 * counters + gauges exposed by `MetricsController` at `/api/metrics`.
 *
 * Six surfaces — same hierarchy as the Grafana panels under
 * `docs/operations/grafana-dashboards/rbac.json`:
 *
 *   - `cortex_permission_denied_total{tenant, role, permission}` — fires
 *     from `EndpointGuardListener` every time a controller method
 *     attribute trips the resolver. Spikes from a single IP/role hint
 *     at credential testing or a misconfigured client.
 *   - `cortex_cross_tenant_access_total{super_admin_id}` — every
 *     `SuperAdminContext::runCrossTenant()` invocation increments. The
 *     panel is intentionally audit-grade visibility, not a deny gate.
 *   - `cortex_api_token_created_total{tenant, scope}` — every
 *     `POST /api/api-tokens` creation. Helps ops correlate downstream
 *     integration changes with rotation windows.
 *   - `cortex_mfa_enrollment_percentage{tenant}` — gauge updated by
 *     a daily cron (or on every enrol/disable from TwoFactorController)
 *     so the dashboard panel shows tenant compliance with the
 *     "enforce MFA on Owner/SA" policy.
 *   - `cortex_failed_login_attempts_total{tenant}` — every 401 from
 *     /api/auth/login. Burst from a single IP → brute-force alert.
 *   - `cortex_super_admin_recovery_total` — every successful
 *     `POST /api/admin/break-glass` invocation. Always Slack-notify.
 *
 * Same worker-scoped-state caveat as {@see QueryDurationHistogram}:
 * `cortex_*` numbers reflect the worker that handled the scrape; with
 * multiple FrankenPHP workers behind Caddy the dashboard sums across
 * scrapes via the rolling-max recording rule defined in
 * `docs/operations/grafana-dashboards/rbac.json`.
 */
final class RbacMetricsRegistry
{
    /**
     * Counters keyed by label string `"key1=value1,key2=value2"` per Prometheus
     * exposition format. Empty-label series uses an empty-string key.
     *
     * @var array<string, array<string, int>> indexed [metricName][labelKey] => count
     */
    private array $counters = [
        'cortex_permission_denied_total' => [],
        'cortex_cross_tenant_access_total' => [],
        'cortex_api_token_created_total' => [],
        'cortex_failed_login_attempts_total' => [],
        'cortex_super_admin_recovery_total' => [],
    ];

    /**
     * Gauges keyed the same way as counters. Last-write-wins per label set.
     *
     * @var array<string, array<string, float>>
     */
    private array $gauges = [
        'cortex_mfa_enrollment_percentage' => [],
    ];

    /**
     * @param array<string, string> $labels alphabetically stable label set
     */
    public function incrementPermissionDenied(array $labels): void
    {
        $this->increment('cortex_permission_denied_total', $labels);
    }

    public function incrementCrossTenantAccess(string $superAdminId): void
    {
        $this->increment('cortex_cross_tenant_access_total', ['super_admin_id' => $superAdminId]);
    }

    /**
     * @param array<string, string> $labels alphabetically stable label set
     */
    public function incrementApiTokenCreated(array $labels): void
    {
        $this->increment('cortex_api_token_created_total', $labels);
    }

    public function incrementFailedLogin(string $tenant): void
    {
        $this->increment('cortex_failed_login_attempts_total', ['tenant' => $tenant]);
    }

    public function incrementSuperAdminRecovery(): void
    {
        $this->increment('cortex_super_admin_recovery_total', []);
    }

    public function setMfaEnrollmentPercentage(string $tenant, float $percentage): void
    {
        $this->gauges['cortex_mfa_enrollment_percentage'][$this->serializeLabels(['tenant' => $tenant])] = $percentage;
    }

    /**
     * @param array<string, string> $labels
     */
    private function increment(string $metric, array $labels): void
    {
        $key = $this->serializeLabels($labels);
        $this->counters[$metric][$key] = ($this->counters[$metric][$key] ?? 0) + 1;
    }

    /**
     * @param array<string, string> $labels
     */
    private function serializeLabels(array $labels): string
    {
        if ([] === $labels) {
            return '';
        }
        ksort($labels);
        $parts = [];
        foreach ($labels as $name => $value) {
            $escaped = str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
            $parts[] = $name.'="'.$escaped.'"';
        }

        return implode(',', $parts);
    }

    public function render(): string
    {
        $lines = [];

        foreach ($this->counters as $metric => $series) {
            $lines[] = '# HELP '.$metric.' '.$this->helpFor($metric);
            $lines[] = '# TYPE '.$metric.' counter';
            if ([] === $series) {
                $lines[] = $metric.' 0';
                continue;
            }
            foreach ($series as $labelKey => $count) {
                $label = '' === $labelKey ? '' : '{'.$labelKey.'}';
                $lines[] = $metric.$label.' '.$count;
            }
        }

        foreach ($this->gauges as $metric => $series) {
            $lines[] = '# HELP '.$metric.' '.$this->helpFor($metric);
            $lines[] = '# TYPE '.$metric.' gauge';
            foreach ($series as $labelKey => $value) {
                $label = '' === $labelKey ? '' : '{'.$labelKey.'}';
                $lines[] = $metric.$label.' '.$value;
            }
        }

        return implode("\n", $lines)."\n";
    }

    private function helpFor(string $metric): string
    {
        return match ($metric) {
            'cortex_permission_denied_total' => 'Number of 403 denials emitted by EndpointGuardListener, labelled by tenant, role, and permission code.',
            'cortex_cross_tenant_access_total' => 'Number of cross-tenant operations issued via SuperAdminContext::runCrossTenant(), labelled by acting super admin id.',
            'cortex_api_token_created_total' => 'Number of API tokens minted via POST /api/api-tokens, labelled by tenant and scope template.',
            'cortex_mfa_enrollment_percentage' => 'Share of authenticated users in the tenant who have MFA enrolled, updated by a daily cron + on every enrol/disable.',
            'cortex_failed_login_attempts_total' => 'Number of 401 responses on /api/auth/login, labelled by tenant.',
            'cortex_super_admin_recovery_total' => 'Number of successful POST /api/admin/break-glass invocations. Audit-grade — always notify Slack #security.',
            default => 'Harmon RBAC counter.',
        };
    }
}
