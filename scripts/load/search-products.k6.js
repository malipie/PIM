// GOLIVE #2124 — Meilisearch-backed product search under load.
//
// Rotates realistic query terms over GET /api/search/products so Meili (not
// Postgres) carries the read; terms match the pim:load:seed value shape.

import { check, group } from 'k6';
import http from 'k6/http';
import { baseUrl, bearerHeaders, defaultOptions } from './lib.js';

export const options = defaultOptions();

const headers = bearerHeaders();
const terms = (__ENV.K6_SEARCH_TERMS || 'value,load,0001,product,benchmark').split(',');

export default function () {
  const term = terms[Math.floor(Math.random() * terms.length)];
  group('GET /api/search/products', () => {
    const res = http.get(`${baseUrl}/api/search/products?q=${encodeURIComponent(term)}`, {
      headers,
    });
    check(res, {
      'status is 200': (r) => r.status === 200,
      'JSON body': (r) => typeof r.body === 'string' && r.body.startsWith('{'),
    });
  });
}
