import http from 'k6/http';
import { check } from 'k6';
import { Rate } from 'k6/metrics';
import { baseUrl } from './lib/common.js';

const queuedOrderRate = new Rate('queued_order_response_rate');

export const options = {
  scenarios: {
    async_after: {
      executor: 'constant-vus',
      vus: 5,
      duration: '15s',
    },
  },
  thresholds: {
    checks: ['rate>0.95'],
    http_req_duration: ['p(95)<1000'],
    queued_order_response_rate: ['rate>0.80'],
  },
};

export default function () {
  const response = http.post(`${baseUrl}/api/after/orders`, JSON.stringify({
    customer_email: `async-${__VU}-${__ITER}@example.com`,
    items: [{ product_id: 2, quantity: 1 }],
  }), {
    headers: { 'Content-Type': 'application/json' },
  });

  queuedOrderRate.add(response.status === 201);

  check(response, {
    'after returns while receipt job is queued': (r) => r.status === 201 || r.status === 409 || r.status === 422,
  });
}
