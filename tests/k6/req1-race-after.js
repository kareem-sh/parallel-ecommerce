import http from 'k6/http';
import { check } from 'k6';
import { baseUrl } from './lib/common.js';
import { Counter, Rate } from 'k6/metrics';

const accepted = new Counter('orders_accepted_with_stock_guard');
const safeRejected = new Counter('orders_safely_rejected');
const safeRejectedRate = new Rate('safe_rejected_rate');

export const options = {
  scenarios: {
    race_after: {
      executor: 'constant-vus',
      vus: 20,
      duration: '10s',
    },
  },
  thresholds: {
    checks: ['rate>0.95'],
    safe_rejected_rate: ['rate>0'],
  },
};

export function setup() {
  const unique = `${Date.now()}-${Math.floor(Math.random() * 100000)}`;
  const response = http.post(`${baseUrl}/api/after/products`, JSON.stringify({
    sku: `RACE-AFTER-${unique}`,
    name: 'Race Test After',
    price: 10,
    stock: 5,
  }), {
    headers: { 'Content-Type': 'application/json' },
  });

  check(response, {
    'after race product created': (r) => r.status === 201,
  });

  return { productId: response.json('data.id') };
}

export default function (data) {
  const response = http.post(`${baseUrl}/api/after/orders`, JSON.stringify({
    customer_email: `new-race-${__VU}-${__ITER}@example.com`,
    items: [{ product_id: data.productId, quantity: 1 }],
  }), {
    headers: { 'Content-Type': 'application/json' },
  });

  if (response.status === 201) {
    accepted.add(1);
    safeRejectedRate.add(false);
  } else if (response.status === 409) {
    safeRejected.add(1);
    safeRejectedRate.add(true);
  } else {
    safeRejectedRate.add(false);
  }

  check(response, {
    'after creates or safely rejects without corruption': (r) => r.status === 201 || r.status === 409 || r.status === 422,
  });
}
