// GOLIVE #2124 — export preflight (row count + sync/async routing) under load.
//
// POST /api/exports/preflight with target_scope=all is the cheapest
// representative slice of the export path: it authorises (RBAC exports:run),
// resolves the entity type and COUNTs the 50k-row scope. Full export runs
// mutate MinIO and the export tables — B2 triggers those once, manually.

import { check, group } from 'k6';
import http from 'k6/http';
import { baseUrl, bearerHeaders, defaultOptions } from './lib.js';

export const options = defaultOptions();

const headers = bearerHeaders({ 'Content-Type': 'application/json' });
const body = JSON.stringify({
  entity_type: __ENV.K6_EXPORT_ENTITY || 'product',
  target_scope: 'all',
});

export default function () {
  group('POST /api/exports/preflight', () => {
    const res = http.post(`${baseUrl}/api/exports/preflight`, body, { headers });
    check(res, {
      'status is 200': (r) => r.status === 200,
      'returns count + mode': (r) =>
        typeof r.body === 'string' && r.body.includes('"count"') && r.body.includes('"mode"'),
    });
  });
}
