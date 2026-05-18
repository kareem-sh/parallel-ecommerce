import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  scenarios: {
    balanced_reads: {
      executor: 'constant-vus',
      vus: 12,
      duration: '30s',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.05'],
    http_req_duration: ['p(95)<1000'],
  },
};

const baseUrl = __ENV.BASE_URL || 'http://localhost:8000';

export function setup() {
  http.get(`${baseUrl}/api/after/products?limit=20`);
}

export default function () {
  const response = http.get(`${baseUrl}/api/after/products?limit=20`);

  check(response, {
    'load balancer returns healthy response': (r) => r.status === 200,
    'response came from optimized backend': (r) => r.headers['X-Backend-Version'] === 'after',
  });

  sleep(1);
}
