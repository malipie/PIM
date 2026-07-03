// GOLIVE #2124 — bulk-edit preview under load.
//
// POST /api/products/bulk-actions/preview computes a non-mutating diff for a
// sample of the selection — the hot read path behind the "edycja masowa"
// modal. setup() grabs real object ids once; each iteration previews a
// set_attribute over them. The APPLY path is deliberately not hammered here
// (it mutates data and dispatches Messenger jobs) — B2 measures a single
// real apply separately.

import { check, group } from 'k6';
import http from 'k6/http';
import { baseUrl, bearerHeaders, defaultOptions } from './lib.js';

export const options = defaultOptions();

const headers = bearerHeaders({ 'Content-Type': 'application/json' });

export function setup() {
  const res = http.get(`${baseUrl}/api/products`, {
    headers: bearerHeaders(),
    // setup() runs outside the default TLS option context in some k6
    // versions — repeat the insecure flag per request to be safe.
    insecureSkipTLSVerify: true,
  });
  const doc = JSON.parse(res.body);
  const members = doc['hydra:member'] || doc.member || [];
  const ids = members.map((m) => (m['@id'] || '').split('/').pop()).filter(Boolean);
  if (!ids.length) {
    throw new Error('No products found — run pim:load:seed first.');
  }
  return { ids };
}

export default function (data) {
  group('POST /api/products/bulk-actions/preview', () => {
    const body = JSON.stringify({
      action: 'set_attribute',
      target_ids: data.ids,
      payload: { attr: 'load_attr_0001', value: 'k6 preview probe' },
    });
    const res = http.post(`${baseUrl}/api/products/bulk-actions/preview`, body, { headers });
    check(res, {
      'status is 200': (r) => r.status === 200,
      'has sample diff': (r) => typeof r.body === 'string' && r.body.includes('sample'),
    });
  });
}
