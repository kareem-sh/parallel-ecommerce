import http from 'k6/http';
import { check } from 'k6';
import { Counter, Rate } from 'k6/metrics';
import { baseUrl } from './lib/common.js';

const conflicts = new Counter('optimistic_lock_conflicts');
const accepted = new Counter('stock_adjust_accepted');
const conflictRate = new Rate('conflict_rate');

export const options = {
  scenarios: {
    lock_after: {
      executor: 'constant-vus',
      vus: 30,
      duration: '10s',
    },
  },
  thresholds: {
    checks: ['rate>0.95'],
    optimistic_lock_conflicts: ['count>5'],
    conflict_rate: ['rate>0.05'],
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
    expectedVersion: 0,
  };
}

export default function (data) {
  const response = http.post(
    `${baseUrl}/api/after/products/${data.productId}/stock-adjust`,
    JSON.stringify({
      delta: -1,
      expected_version: data.expectedVersion,
    }),
    { headers: { 'Content-Type': 'application/json' } },
  );

  if (response.status === 200) {
    accepted.add(1);
    conflictRate.add(false);
  }

  if (response.status === 409) {
    conflicts.add(1);
    conflictRate.add(true);
  }

  check(response, {
    'after uses optimistic locking semantics': (r) => r.status === 200 || r.status === 409,
    'after conflict mentions requirement 7': (r) =>
      r.status !== 409 || r.json('requirement') === 7,
  });
}
