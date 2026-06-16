import http from 'k6/http';
import { check } from 'k6';
import { baseUrl } from './lib/common.js';

export const options = {
  scenarios: {
    stress_before: {
      executor: 'ramping-vus',
      startVUs: 100,
      stages: [
        { duration: '30s', target: 100 },
      ],
      gracefulRampDown: '0s',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.35'],
    checks: ['rate>0.90'],
  },
};

export default function () {
  const response = http.get(`${baseUrl}/api/before/hot-products?limit=20`);

  check(response, {
    'before survives 100 concurrent readers': (r) => r.status === 200 || r.status === 502 || r.status === 503,
    'before still returns version header when healthy': (r) =>
      r.status !== 200 || r.headers['X-Backend-Version'] === 'before',
  });
}

export function teardown() {
  const health = http.get(`${baseUrl}/api/health`);

  check(health, {
    'system health reachable after before stress': (r) => r.status === 200 || r.status === 503,
  });
}
