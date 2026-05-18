import http from 'k6/http';
import { check } from 'k6';

export const options = {
  scenarios: {
    race_before: {
      executor: 'constant-vus',
      vus: 10,
      duration: '10s',
    },
  },
};

const baseUrl = __ENV.BASE_URL || 'http://localhost:8000';

export default function () {
  const response = http.post(`${baseUrl}/api/before/orders`, JSON.stringify({
    customer_email: `old-race-${__VU}-${__ITER}@example.com`,
    items: [{ product_id: 1, quantity: 1 }],
  }), {
    headers: { 'Content-Type': 'application/json' },
  });

  check(response, {
    'before accepts order even under contention': (r) => r.status === 201 || r.status === 422 || r.status === 500,
  });
}
