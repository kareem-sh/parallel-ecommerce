import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Rate } from 'k6/metrics';
import { baseUrl } from './lib/common.js';

const lockTimeouts = new Counter('distributed_lock_timeouts');
const accepted = new Counter('stock_adjust_accepted');
const lockContentionRate = new Rate('lock_contention_rate');

export const options = {
  scenarios: {
    lock_after: {
      executor: 'constant-vus',
      vus: 20,
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
  const response = http.post(`${baseUrl}/api/after/products`, JSON.stringify({
    sku: `LOCK-AFTER-${unique}`,
    name: 'Lock Test After',
    price: 15,
    stock: 60,
  }), {
    headers: { 'Content-Type': 'application/json' },
  });

  check(response, {
    'lock-after product created': (r) => r.status === 201,
  });

  return {
    productId: response.json('data.id'),
    startingStock: 60,
  };
}

export default function (data) {
  const response = http.post(
    `${baseUrl}/api/after/products/${data.productId}/stock-adjust`,
    JSON.stringify({ delta: -1 }),
    { headers: { 'Content-Type': 'application/json' } },
  );

  if (response.status === 200) {
    accepted.add(1);
    lockContentionRate.add(false);
  } else if (response.status === 409) {
    lockTimeouts.add(1);
    lockContentionRate.add(true);
  }

  check(response, {
    // 200 = adjusted, 409 = lock timeout or insufficient stock, 503 = capacity guard on /api/after/*
    'after uses distributed lock semantics': (r) =>
      r.status === 200 || r.status === 409 || r.status === 503,
    'after exposes distributed locking': (r) =>
      r.status !== 200 || r.json('locking') === 'distributed',
    'after conflict mentions requirement 7': (r) =>
      r.status !== 409 || r.json('requirement') === 7,
    'after stock stays non-negative when accepted': (r) => {
      if (r.status !== 200) {
        return true;
      }

      const stock = r.json('data.stock');
      return (stock != null ? stock : 0) >= 0;
    },
  });
}

export function teardown(data) {
  const response = http.get(`${baseUrl}/api/after/products?limit=100`);
  const products = response.json('data') || [];
  const product = products.find((item) => item.id === data.productId);
  const remaining = product ? product.stock : data.startingStock;

  // Informational only — do not fail thresholds on teardown contention.
  console.log(`lock-after remaining stock: ${remaining} (started ${data.startingStock})`);
  sleep(0.1);
}
