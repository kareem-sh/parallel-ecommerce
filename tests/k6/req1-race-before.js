import http from 'k6/http';
import { check } from 'k6';
import { baseUrl } from './lib/common.js';
import { Counter, Rate } from 'k6/metrics';

const accepted = new Counter('orders_accepted_without_stock_guard');
const rejected = new Counter('orders_rejected_or_failed');
const acceptedRate = new Rate('accepted_rate');

export const options = {
  scenarios: {
    race_before: {
      executor: 'constant-vus',
      vus: 20,
      duration: '10s',
    },
  },
  thresholds: {
    checks: ['rate>0.95'],
  },
};

export function setup() {
  const unique = `${Date.now()}-${Math.floor(Math.random() * 100000)}`;
  const response = http.post(`${baseUrl}/api/before/products`, JSON.stringify({
    sku: `RACE-BEFORE-${unique}`,
    name: 'Race Test Before',
    price: 10,
    stock: 5,
  }), {
    headers: { 'Content-Type': 'application/json' },
  });

  check(response, {
    'before race product created': (r) => r.status === 201,
  });

  return { productId: response.json('data.id') };
}

export default function (data) {
  const response = http.post(`${baseUrl}/api/before/orders`, JSON.stringify({
    customer_email: `old-race-${__VU}-${__ITER}@example.com`,
    items: [{ product_id: data.productId, quantity: 1 }],
  }), {
    headers: { 'Content-Type': 'application/json' },
  });

  if (response.status === 201) {
    accepted.add(1);
    acceptedRate.add(true);
  } else {
    rejected.add(1);
    acceptedRate.add(false);
  }

  check(response, {
    'before accepts or exposes unsafe failure under contention': (r) => r.status === 201 || r.status === 422 || r.status >= 500,
  });
}
