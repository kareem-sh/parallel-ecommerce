import http from 'k6/http';
import { check } from 'k6';
import { Counter, Rate } from 'k6/metrics';
import { baseUrl } from './lib/common.js';

const partialFailures = new Counter('checkout_partial_failures');
const partialRate = new Rate('partial_failure_rate');

export const options = {
  scenarios: {
    acid_before: {
      executor: 'constant-vus',
      vus: 15,
      duration: '10s',
    },
  },
  thresholds: {
    checks: ['rate>0.95'],
    partial_failure_rate: ['rate>0.8'],
  },
};

export function setup() {
  const unique = `${Date.now()}-${Math.floor(Math.random() * 100000)}`;
  const response = http.post(`${baseUrl}/api/before/products`, JSON.stringify({
    sku: `ACID-BEFORE-${unique}`,
    name: 'ACID Test Before',
    price: 25,
    stock: 200,
  }), {
    headers: { 'Content-Type': 'application/json' },
  });

  check(response, {
    'acid-before product created': (r) => r.status === 201,
  });

  return { productId: response.json('data.id') };
}

export default function (data) {
  const response = http.post(`${baseUrl}/api/before/checkout`, JSON.stringify({
    customer_email: `acid-before-${__VU}-${__ITER}@example.com`,
    fail_payment: true,
    items: [{ product_id: data.productId, quantity: 1 }],
  }), {
    headers: { 'Content-Type': 'application/json' },
  });

  if (response.status === 402) {
    partialFailures.add(1);
    partialRate.add(true);
  } else {
    partialRate.add(false);
  }

  check(response, {
    'before checkout fails after partial writes': (r) => r.status === 402,
    'before exposes partial_state_possible': (r) => r.json('partial_state_possible') === true,
  });
}
