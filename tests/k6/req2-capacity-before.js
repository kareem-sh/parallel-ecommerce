import http from 'k6/http';
import { check } from 'k6';
import { baseUrl } from './lib/common.js';

export const options = {
  scenarios: {
    unbounded_before: {
      executor: 'constant-vus',
      vus: 80,
      duration: '15s',
    },
  },
};

export default function () {
  const response = http.get(`${baseUrl}/api/before/products?limit=50`);

  check(response, {
    'before returns or struggles without capacity guard': (r) => r.status === 200 || r.status >= 500,
  });
}
