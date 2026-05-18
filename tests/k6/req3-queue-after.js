import http from 'k6/http';
import { check } from 'k6';

export const options = {
  scenarios: {
    async_after: {
      executor: 'constant-vus',
      vus: 5,
      duration: '15s',
    },
  },
};

const baseUrl = __ENV.BASE_URL || 'http://localhost:8000';

export default function () {
  const response = http.post(`${baseUrl}/api/after/orders`, JSON.stringify({
    customer_email: `async-${__VU}-${__ITER}@example.com`,
    items: [{ product_id: 2, quantity: 1 }],
  }), {
    headers: { 'Content-Type': 'application/json' },
  });

  check(response, {
    'after returns while receipt job is queued': (r) => r.status === 201 || r.status === 409 || r.status === 422,
  });
}
