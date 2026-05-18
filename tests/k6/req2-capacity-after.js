import http from 'k6/http';
import { check } from 'k6';

export const options = {
  scenarios: {
    guarded_after: {
      executor: 'constant-vus',
      vus: 80,
      duration: '15s',
    },
  },
};

const baseUrl = __ENV.BASE_URL || 'http://localhost:8000';

export default function () {
  const response = http.get(`${baseUrl}/api/after/products?limit=50`);

  check(response, {
    'after controls capacity with success or intentional rejection': (r) => r.status === 200 || r.status === 503 || r.status === 429,
  });
}
