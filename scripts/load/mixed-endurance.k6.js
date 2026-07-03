// GOLIVE #2124 — mixed-traffic endurance run (B2: 2-4h, worker-memory watch).
//
// Weighted read-mostly mix approximating admin + integrator traffic:
//   55% product list (keyset walk)  ·  30% Meili search
//   10% export preflight            ·   5% bulk preview
// Run for hours while watching `frankenphp_worker_memory_bytes` — the
// Identity-Map leak class only shows up on long mixed runs, not on
// single-endpoint bursts.

import { check } from 'k6';
import http from 'k6/http';
import { baseUrl, bearerHeaders, defaultOptions } from './lib.js';

export const options = defaultOptions({
  vus: parseInt(__ENV.K6_VUS || '10', 10),
  duration: __ENV.K6_DURATION || '2h',
});

const headers = bearerHeaders();
const jsonHeaders = bearerHeaders({ 'Content-Type': 'application/json' });
const terms = ['value', 'load', 'product', '0042'];

export function setup() {
  const res = http.get(`${baseUrl}/api/products`, { headers });
  const doc = JSON.parse(res.body);
  const members = doc['hydra:member'] || doc.member || [];
  return { ids: members.map((m) => (m['@id'] || '').split('/').pop()).filter(Boolean) };
}

export default function (data) {
  const dice = Math.random();
  let res;
  if (dice < 0.55) {
    res = http.get(`${baseUrl}/api/products?order[id]=desc`, { headers });
  } else if (dice < 0.85) {
    const term = terms[Math.floor(Math.random() * terms.length)];
    res = http.get(`${baseUrl}/api/search/products?q=${term}`, { headers });
  } else if (dice < 0.95) {
    res = http.post(
      `${baseUrl}/api/exports/preflight`,
      JSON.stringify({ entity_type: 'product', target_scope: 'all' }),
      { headers: jsonHeaders },
    );
  } else {
    res = http.post(
      `${baseUrl}/api/products/bulk-actions/preview`,
      JSON.stringify({
        action: 'set_attribute',
        target_ids: data.ids,
        payload: { attr: 'load_attr_0001', value: 'endurance probe' },
      }),
      { headers: jsonHeaders },
    );
  }
  check(res, { 'status is 200': (r) => r.status === 200 });
}
