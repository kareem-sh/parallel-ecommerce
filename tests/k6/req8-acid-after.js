import http from 'k6/http';
import { check } from 'k6';
import { Counter, Rate } from 'k6/metrics';
import { baseUrl } from './lib/common.js';

const rolledBack = new Counter('checkout_rolled_back');
const rollbackRate = new Rate('rollback_rate');

export const options = {
  scenarios: {
    acid_after: {
      executor: 'constant-vus',
      vus: 15,
      duration: '10s',
    },
  },
  thresholds: {
    checks: ['rate>0.95'],
    rollback_rate: ['rate>0.8'],
  },
};

export function setup() {
  const unique = `${Date.now()}-${Math.floor(Math.random() * 100000)}`;
  const response = http.post(`${baseUrl}/api/after/products`, JSON.stringify({
    sku: `ACID-AFTER-${unique}`,
    name: 'ACID Test After',
    price: 25,
    stock: 200,
  }), {
    headers: { 'Content-Type': 'application/json' },
  });

  check(response, {
    'acid-after product created': (r) => r.status === 201,
  });

  return { productId: response.json('data.id') };
}

export default function (data) {
  const response = http.post(`${baseUrl}/api/after/checkout`, JSON.stringify({
    customer_email: `acid-after-${__VU}-${__ITER}@example.com`,
    fail_payment: true,
    items: [{ product_id: data.productId, quantity: 1 }],
  }), {
    headers: { 'Content-Type': 'application/json' },
  });

  if (response.status === 402) {
    rolledBack.add(1);
    rollbackRate.add(true);
  } else {
    rollbackRate.add(false);
  }

  check(response, {
    'after checkout rolls back atomically': (r) => r.status === 402,
    'after marks rolled_back true': (r) => r.json('rolled_back') === true,
  });
}
