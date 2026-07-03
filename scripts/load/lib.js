// Shared helpers for the GOLIVE load-test scenarios (#2124).
//
// Every scenario reads the same env contract:
//   API_TOKEN    — JWT bearer minted via POST /api/auth/login (required unless the scenario says otherwise)
//   K6_BASE_URL  — defaults to https://pim.localhost
//   K6_VUS / K6_DURATION — scenario size (each script has its own defaults)
//   K6_P95_MS    — latency gate, defaults to 300 (GOLIVE plan: p95 < 300 ms per endpoint)

export const baseUrl = __ENV.K6_BASE_URL || 'https://pim.localhost';

// k6 shares the caddy container's network namespace (network_mode:
// service:caddy) but keeps its OWN /etc/hosts, and Docker's embedded DNS
// only resolves service names — pim.localhost must be pinned to the
// loopback where caddy listens. Override with K6_TARGET_IP when pointing
// at a remote (prod-like) host.
export const hosts = { 'pim.localhost': __ENV.K6_TARGET_IP || '127.0.0.1' };

export function bearerHeaders(extra = {}) {
  const token = __ENV.API_TOKEN;
  if (!token) {
    throw new Error('API_TOKEN env var is required (mint via POST /api/auth/login).');
  }
  return {
    Authorization: `Bearer ${token}`,
    Accept: 'application/ld+json',
    ...extra,
  };
}

export function defaultOptions(overrides = {}) {
  const p95 = parseInt(__ENV.K6_P95_MS || '300', 10);
  return {
    // Self-signed Caddy local CA cert — accepted inside the perf profile only.
    insecureSkipTLSVerify: true,
    hosts,
    vus: parseInt(__ENV.K6_VUS || '20', 10),
    duration: __ENV.K6_DURATION || '60s',
    thresholds: {
      http_req_duration: [`p(95)<${p95}`, `p(99)<${p95 * 2}`],
      http_req_failed: ['rate<0.01'],
    },
    ...overrides,
  };
}
