import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Rate } from 'k6/metrics';
import { baseUrl } from './lib/common.js';

// Req 7 after: Redis distributed lock across app + app2 (nginx after_backend pool).
const STARTING_STOCK = 60;

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
    stock: STARTING_STOCK,
  }), {
    headers: { 'Content-Type': 'application/json' },
  });

  check(response, {
    'lock-after product created': (r) => r.status === 201,
  });

  return {
    productId: response.json('data.id'),
    startingStock: STARTING_STOCK,
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
    'after marks concurrency as safe on success': (r) =>
      r.status !== 200 || r.json('concurrency_safe') === true,
    'after response header shows distributed lock': (r) =>
      r.status !== 200 || r.headers['X-Locking-Strategy'] === 'distributed',
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
  const actualRemoved = data.startingStock - remaining;

  console.log(
    `req7-after demo: started=${data.startingStock} remaining=${remaining} ` +
    `(removed ${actualRemoved}; lock serializes updates across both app servers)`,
  );

  check({ remaining, actualRemoved, startingStock: data.startingStock }, {
    'after lock: inventory reaches zero under concurrent load': ({ remaining }) => remaining === 0,
    'after lock: all starting stock was decremented exactly once': ({ actualRemoved, startingStock }) =>
      actualRemoved === startingStock,
  });

  sleep(0.1);
}
