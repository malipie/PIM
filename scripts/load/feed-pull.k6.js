// GOLIVE #2124 — public XML feed pull (token URL, no session).
//
// GET /api/feeds/pull/{tenantId}/{token}.xml serves the cached artifact via
// StreamedResponse. The endpoint is rate-limited 120/h per feed
// (framework.yaml `feed_pull`), so this scenario is CAPPED by design:
// constant-arrival-rate well under the limiter, asserting 200s and that the
// limiter is NOT hit. Pass the full public URL via FEED_PULL_URL (copy it
// from the feed detail screen).

import { check } from 'k6';
import http from 'k6/http';

const url = __ENV.FEED_PULL_URL;
if (!url) {
  throw new Error('FEED_PULL_URL env var is required (public feed URL with token).');
}

// NOTE: env vars here deliberately avoid the K6_ prefix — k6 treats K6_VUS /
// K6_DURATION as native CLI-option overrides which would REPLACE the
// scenarios block below and hammer the limiter (observed: 13k reqs/min).
export const options = {
  insecureSkipTLSVerify: true,
  hosts: { 'pim.localhost': __ENV.K6_TARGET_IP || '127.0.0.1' },
  scenarios: {
    capped_pulls: {
      executor: 'constant-arrival-rate',
      rate: parseInt(__ENV.FEED_RATE_PER_MINUTE || '1', 10),
      timeUnit: '1m',
      duration: __ENV.FEED_PULL_DURATION || '5m',
      preAllocatedVUs: 2,
    },
  },
  thresholds: {
    http_req_duration: [`p(95)<${parseInt(__ENV.LOAD_P95_MS || '300', 10)}`],
    http_req_failed: ['rate<0.01'],
  },
};

export default function () {
  const res = http.get(url);
  check(res, {
    'status is 200 (not 429 — stay under feed_pull limiter)': (r) => r.status === 200,
    'XML payload': (r) => typeof r.body === 'string' && r.body.startsWith('<?xml'),
  });
}
