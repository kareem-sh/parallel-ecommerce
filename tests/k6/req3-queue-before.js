import http from 'k6/http';
import { check } from 'k6';
import { baseUrl } from './lib/common.js';

export const options = {
  scenarios: {
    sync_before: {
      executor: 'constant-vus',
      vus: 5,
      duration: '15s',
    },
  },
  thresholds: {
    checks: ['rate>0.95'],
  },
};

export function setup() {
  const unique = `${Date.now()}-${Math.floor(Math.random() * 100000)}`;
  const response = http.post(`${baseUrl}/api/before/products`, JSON.stringify({
    sku: `QUEUE-BEFORE-${unique}`,
    name: 'Queue Test Before',
    price: 10,
    stock: 50000,
  }), {
    headers: { 'Content-Type': 'application/json' },
  });

  check(response, {
    'queue-before product created': (r) => r.status === 201,
  });

  return { productId: response.json('data.id') };
}

export default function (data) {
  const response = http.post(`${baseUrl}/api/before/orders`, JSON.stringify({
    customer_email: `sync-${__VU}-${__ITER}@example.com`,
    items: [{ product_id: data.productId, quantity: 1 }],
  }), {
    headers: { 'Content-Type': 'application/json' },
  });

  check(response, {
    'before synchronous order path completed': (r) => r.status === 201 || r.status === 422,
  });
}
