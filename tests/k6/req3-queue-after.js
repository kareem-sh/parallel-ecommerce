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

export function setup() {
  const unique = `${Date.now()}-${Math.floor(Math.random() * 100000)}`;
  const response = http.post(`${baseUrl}/api/after/products`, JSON.stringify({
    sku: `QUEUE-AFTER-${unique}`,
    name: 'Queue Test After',
    price: 10,
    stock: 50000,
  }), {
    headers: { 'Content-Type': 'application/json' },
  });

  check(response, {
    'queue-after product created': (r) => r.status === 201,
  });

  return { productId: response.json('data.id') };
}

export default function (data) {
  const response = http.post(`${baseUrl}/api/after/orders`, JSON.stringify({
    customer_email: `async-${__VU}-${__ITER}@example.com`,
    items: [{ product_id: data.productId, quantity: 1 }],
  }), {
    headers: { 'Content-Type': 'application/json' },
  });

  queuedOrderRate.add(response.status === 201);

  check(response, {
    'after returns while receipt job is queued': (r) => r.status === 201 || r.status === 409 || r.status === 422,
  });
}
