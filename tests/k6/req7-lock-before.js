import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';
import { baseUrl } from './lib/common.js';

const adjustments = new Counter('stock_adjust_attempts');
const accepted = new Counter('stock_adjust_accepted');

export const options = {
  scenarios: {
    lock_before: {
      executor: 'constant-vus',
      vus: 30,
      duration: '10s',
    },
  },
  thresholds: {
    checks: ['rate>0.95'],
    stock_adjust_accepted: ['count>20'],
  },
};

export function setup() {
  const unique = `${Date.now()}-${Math.floor(Math.random() * 100000)}`;
  const response = http.post(`${baseUrl}/api/before/products`, JSON.stringify({
    sku: `LOCK-BEFORE-${unique}`,
    name: 'Lock Test Before',
    price: 15,
    stock: 60,
  }), {
    headers: { 'Content-Type': 'application/json' },
  });

  check(response, {
    'lock-before product created': (r) => r.status === 201,
  });

  return {
    productId: response.json('data.id'),
    startingStock: 60,
  };
}

export default function (data) {
  adjustments.add(1);

  const response = http.post(
    `${baseUrl}/api/before/products/${data.productId}/stock-adjust`,
    JSON.stringify({ delta: -1 }),
    { headers: { 'Content-Type': 'application/json' } },
  );

  if (response.status === 200) {
    accepted.add(1);
  }

  check(response, {
    'before stock adjust has no lock metadata': (r) =>
      r.status === 200 && r.json('locking') === 'none',
  });
}

export function teardown(data) {
  const response = http.get(`${baseUrl}/api/before/products?limit=100`);
  const products = response.json('data') || [];
  const product = products.find((item) => item.id === data.productId);
  const remaining = product ? product.stock : data.startingStock;

  check({ remaining }, {
    'before keeps too much stock after concurrent decrements': () => remaining > 20,
  });
}
