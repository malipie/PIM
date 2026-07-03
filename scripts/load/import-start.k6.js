// GOLIVE #2124 — import start smoke (rate-limited path).
//
// POST /api/import-sessions is limited to 20/h per tenant (import_trigger),
// so this is a SMOKE: a single multipart import of a 3-row CSV (<50 rows =
// inline sync path), asserting 200/201 and a session id. Big-file import
// timing is measured once, manually, in the B2 session — not via k6.
//
// Requires TARGET_OBJECT_TYPE_ID (built-in product ObjectType UUID); the
// run-load-session.sh wrapper resolves it automatically.

import { check } from 'k6';
import http from 'k6/http';
import { baseUrl, bearerHeaders, hosts } from './lib.js';

const targetTypeId = __ENV.TARGET_OBJECT_TYPE_ID;
if (!targetTypeId) {
  throw new Error('TARGET_OBJECT_TYPE_ID env var is required (product ObjectType UUID).');
}

export const options = {
  insecureSkipTLSVerify: true,
  hosts,
  vus: 1,
  iterations: 1,
  thresholds: { checks: ['rate==1.0'] },
};

const csv =
  'sku,name\nk6-smoke-001,K6 smoke product 1\nk6-smoke-002,K6 smoke product 2\nk6-smoke-003,K6 smoke product 3\n';

export default function () {
  const payload = {
    file: http.file(csv, 'k6-smoke.csv', 'text/csv'),
    target_object_type_id: targetTypeId,
    mapping: JSON.stringify({ sku: 'sku', name: 'name' }),
    mode: 'upsert',
  };
  const res = http.post(`${baseUrl}/api/import-sessions`, payload, { headers: bearerHeaders() });
  check(res, {
    'status is 200/201/202': (r) => [200, 201, 202].includes(r.status),
    'session id returned': (r) => typeof r.body === 'string' && r.body.includes('id'),
  });
}
