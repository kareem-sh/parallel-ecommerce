import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  scenarios: {
    create_orders: {
      executor: 'constant-vus',
      vus: 5,
      duration: '20s',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.20'],
  },
};

const baseUrl = __ENV.BASE_URL || 'http://localhost:8000';

export default function () {
  const payload = JSON.stringify({
    customer_email: `buyer-${__VU}-${__ITER}@example.com`,
    items: [
      { product_id: 1, quantity: 1 },
    ],
  });

  const response = http.post(`${baseUrl}/api/after/orders`, payload, {
    headers: { 'Content-Type': 'application/json' },
  });

  check(response, {
    'order created or safely rejected': (r) => r.status === 201 || r.status === 409 || r.status === 422,
  });

  sleep(1);
}
