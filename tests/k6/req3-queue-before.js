import http from 'k6/http';
import { check } from 'k6';

export const options = {
  scenarios: {
    sync_before: {
      executor: 'constant-vus',
      vus: 5,
      duration: '15s',
    },
  },
};

const baseUrl = __ENV.BASE_URL || 'http://localhost:8000';

export default function () {
  const response = http.post(`${baseUrl}/api/before/orders`, JSON.stringify({
    customer_email: `sync-${__VU}-${__ITER}@example.com`,
    items: [{ product_id: 2, quantity: 1 }],
  }), {
    headers: { 'Content-Type': 'application/json' },
  });

  check(response, {
    'before synchronous order path completed': (r) => r.status === 201 || r.status === 422,
  });
}
