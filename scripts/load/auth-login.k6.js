// GOLIVE #2124 — login smoke under the auth_login limiter.
//
// The limiter is 5 attempts / IP / 15 min (fixed window) — a login "load
// test" is therefore a SMOKE by design: 4 sequential iterations, asserting
// 200 + token and that the limiter never fires. Hammering this endpoint
// harder only measures the 429 path. K6_LOGIN_EMAIL/PASSWORD default to the
// dev fixtures admin.

import { check, sleep } from 'k6';
import http from 'k6/http';
import { baseUrl, hosts } from './lib.js';

export const options = {
  insecureSkipTLSVerify: true,
  hosts,
  vus: 1,
  iterations: parseInt(__ENV.K6_LOGIN_ITERATIONS || '4', 10),
  thresholds: {
    http_req_duration: [`p(95)<${parseInt(__ENV.K6_P95_MS || '300', 10)}`],
    checks: ['rate==1.0'],
  },
};

const body = JSON.stringify({
  email: __ENV.K6_LOGIN_EMAIL || 'admin@demo.localhost',
  password: __ENV.K6_LOGIN_PASSWORD || 'changeme',
});

export default function () {
  const res = http.post(`${baseUrl}/api/auth/login`, body, {
    headers: { 'Content-Type': 'application/json' },
  });
  check(res, {
    'status is 200 (limiter not hit)': (r) => r.status === 200,
    'body has token': (r) => typeof r.body === 'string' && r.body.includes('"token"'),
  });
  sleep(1);
}
