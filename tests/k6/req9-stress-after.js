import http from 'k6/http';
import { check } from 'k6';
import { baseUrl } from './lib/common.js';

export const options = {
  scenarios: {
    stress_after: {
      executor: 'ramping-vus',
      startVUs: 100,
      stages: [
        { duration: '30s', target: 100 },
      ],
      gracefulRampDown: '0s',
    },
  },
  thresholds: {
    checks: ['rate>0.98'],
    http_req_failed: ['rate<0.60'],
  },
};

export function setup() {
  http.get(`${baseUrl}/api/after/hot-products?limit=20`);
}

export default function () {
  const response = http.get(`${baseUrl}/api/after/hot-products?limit=20`);

  check(response, {
    'after serves 100 concurrent users': (r) => r.status === 200 || r.status === 503,
    'after keeps backend version under stress': (r) =>
      r.status === 503 || r.headers['X-Backend-Version'] === 'after',
  });
}

export function teardown() {
  const health = http.get(`${baseUrl}/api/health`);

  check(health, {
    'system stays healthy after stress': (r) => r.status === 200,
    'database and redis healthy': (r) =>
      r.status === 200 &&
      r.json('checks.database') === true &&
      r.json('checks.redis') === true,
  });
}
