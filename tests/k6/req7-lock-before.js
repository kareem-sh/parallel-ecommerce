import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';
import { baseUrl } from './lib/common.js';

// Req 7 before: single app is enough — PHP-FPM workers still race without a lock.
const STARTING_STOCK = 1000;

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
    stock_adjust_accepted: ['count>100'],
  },
};

export function setup() {
  const unique = `${Date.now()}-${Math.floor(Math.random() * 100000)}`;
  const response = http.post(`${baseUrl}/api/before/products`, JSON.stringify({
    sku: `LOCK-BEFORE-${unique}`,
    name: 'Lock Test Before',
    price: 15,
    stock: STARTING_STOCK,
  }), {
    headers: { 'Content-Type': 'application/json' },
  });

  check(response, {
    'lock-before product created': (r) => r.status === 201,
  });

  return {
    productId: response.json('data.id'),
    startingStock: STARTING_STOCK,
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
    'before exposes requirement 7 problem': (r) =>
      r.status === 200 && r.json('requirement') === 7,
    'before stock adjust has no lock metadata': (r) =>
      r.status === 200 && r.json('locking') === 'none',
    'before marks concurrency as unsafe': (r) =>
      r.status === 200 && r.json('concurrency_safe') === false,
    'before response header shows no lock strategy': (r) =>
      r.status === 200 && r.headers['X-Locking-Strategy'] === 'none',
  });
}

export function teardown(data) {
  const response = http.get(`${baseUrl}/api/before/products?limit=100`);
  const products = response.json('data') || [];
  const product = products.find((item) => item.id === data.productId);
  const remaining = product ? product.stock : data.startingStock;
  const actualRemoved = data.startingStock - remaining;

  console.log(
    `req7-before demo: started=${data.startingStock} remaining=${remaining} ` +
    `(only ${actualRemoved} units removed despite hundreds of HTTP 200 responses)`,
  );

  check({ remaining, actualRemoved, startingStock: data.startingStock }, {
    'before race: lost updates leave inventory too high': ({ remaining, startingStock }) =>
      remaining > startingStock * 0.85,
    'before race: most HTTP 200s did not change stock': ({ actualRemoved, startingStock }) =>
      actualRemoved < startingStock * 0.15,
  });
}
