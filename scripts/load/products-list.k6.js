// GOLIVE #2124 — cursor-paginated product list under load.
//
// Each iteration walks K6_PAGES pages (default 3) of GET /api/products using
// keyset pagination (`id[lt]=<last id>`), the way the admin grid and API
// integrators read the catalog at 50k SKU.

import { check, group } from 'k6';
import http from 'k6/http';
import { baseUrl, bearerHeaders, defaultOptions } from './lib.js';

export const options = defaultOptions();

const headers = bearerHeaders();
const pages = parseInt(__ENV.K6_PAGES || '3', 10);

function lastMemberId(body) {
  try {
    const doc = JSON.parse(body);
    const members = doc['hydra:member'] || doc.member || [];
    if (!members.length) return null;
    const iri = members[members.length - 1]['@id'] || '';
    return iri.split('/').pop() || null;
  } catch {
    return null;
  }
}

export default function () {
  group('GET /api/products keyset walk', () => {
    let cursor = null;
    for (let p = 0; p < pages; p++) {
      const url = cursor
        ? `${baseUrl}/api/products?order[id]=desc&id[lt]=${cursor}`
        : `${baseUrl}/api/products?order[id]=desc`;
      const res = http.get(url, { headers });
      check(res, {
        'status is 200': (r) => r.status === 200,
        'JSON-LD collection': (r) => typeof r.body === 'string' && r.body.includes('"@type"'),
      });
      cursor = res.status === 200 ? lastMemberId(res.body) : null;
      if (!cursor) break;
    }
  });
}
