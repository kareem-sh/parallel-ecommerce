import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  scenarios: {
    after_load: {
      executor: 'constant-vus',
      vus: 8,
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
  http.get(`${baseUrl}/api/after/orders?limit=20`);
}

export default function () {
  const products = http.get(`${baseUrl}/api/after/products?limit=20`);
  const orders = http.get(`${baseUrl}/api/after/orders?limit=20`);

  check(products, {
    'after products ok': (r) => r.status === 200,
    'after products cache hit': (r) => r.headers['X-Backend-Cache'] === 'hit',
  });

  check(orders, {
    'after orders ok': (r) => r.status === 200,
    'after orders cache hit': (r) => r.headers['X-Backend-Cache'] === 'hit',
  });

  sleep(1);
}
