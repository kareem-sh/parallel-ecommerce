import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  scenarios: {
    before_load: {
      executor: 'constant-vus',
      vus: 15,
      duration: '30s',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.05'],
  },
};

const baseUrl = __ENV.BASE_URL || 'http://localhost:8000';

export default function () {
  const products = http.get(`${baseUrl}/api/before/products?limit=20`);
  const orders = http.get(`${baseUrl}/api/before/orders?limit=20`);

  check(products, {
    'before products ok': (r) => r.status === 200,
    'before products uncached': (r) => r.headers['X-Backend-Version'] === 'before',
  });

  check(orders, {
    'before orders ok': (r) => r.status === 200,
  });

  sleep(1);
}
