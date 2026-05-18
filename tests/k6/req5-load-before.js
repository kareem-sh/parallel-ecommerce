import http from 'k6/http';
import { check } from 'k6';

export const options = {
  scenarios: {
    direct_before: {
      executor: 'constant-vus',
      vus: 12,
      duration: '20s',
    },
  },
};

const baseUrl = __ENV.DIRECT_BASE_URL || 'http://app:8000';

export default function () {
  const response = http.get(`${baseUrl}/api/after/products?limit=20`);

  check(response, {
    'single app instance responded': (r) => r.status === 200,
  });
}
