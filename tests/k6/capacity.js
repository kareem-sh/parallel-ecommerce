import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  scenarios: {
    capacity_pressure: {
      executor: 'constant-vus',
      vus: 80,
      duration: '15s',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.70'],
  },
};

const baseUrl = __ENV.BASE_URL || 'http://localhost:8000';

export default function () {
  const response = http.get(`${baseUrl}/api/after/products?limit=20`);

  check(response, {
    'capacity allows or rejects intentionally': (r) => r.status === 200 || r.status === 503 || r.status === 429,
  });

  sleep(0.2);
}
