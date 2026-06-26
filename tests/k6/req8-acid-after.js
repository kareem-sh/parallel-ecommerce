import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Rate } from 'k6/metrics';
import { baseUrl } from './lib/common.js';

const STARTING_STOCK = 200;

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
    checkout_rolled_back: ['count>50'],
    rollback_rate: ['rate>0.8'],
  },
};

export function setup() {
  const unique = `${Date.now()}-${Math.floor(Math.random() * 100000)}`;
  const response = http.post(`${baseUrl}/api/after/products`, JSON.stringify({
    sku: `ACID-AFTER-${unique}`,
    name: 'ACID Test After',
    price: 25,
    stock: STARTING_STOCK,
  }), {
    headers: { 'Content-Type': 'application/json' },
  });

  check(response, {
    'acid-after product created': (r) => r.status === 201,
  });

  return {
    productId: response.json('data.id'),
    startingStock: STARTING_STOCK,
  };
}

export default function (data) {
  const response = http.post(`${baseUrl}/api/after/checkout`, JSON.stringify({
    customer_email: `acid-after-${__VU}-${__ITER}@example.com`,
    fail_payment: true,
    items: [{ product_id: data.productId, quantity: 1 }],
  }), {
    headers: { 'Content-Type': 'application/json' },
  });

  const isRollback = response.status === 402 && response.json('rolled_back') === true;

  if (isRollback) {
    rolledBack.add(1);
    rollbackRate.add(true);
  } else {
    rollbackRate.add(false);
  }

  check(response, {
    'after checkout rolls back atomically (402)': (r) => r.status === 402,
    'after marks rolled_back true': (r) => r.json('rolled_back') === true,
    'after rollback mentions requirement 8': (r) => r.json('requirement') === 8,
    'after rollback header is set': (r) => r.headers['X-Transaction-Rolled-Back'] === 'true',
    'after rollback message mentions transaction': (r) =>
      String(r.json('message') || '').toLowerCase().includes('rolled back'),
  });
}

export function teardown(data) {
  const products = http.get(`${baseUrl}/api/after/products?limit=100`).json('data') || [];
  const product = products.find((item) => item.id === data.productId);
  const remainingStock = product ? product.stock : null;

  console.log(
    `req8-after demo: checkout_rolled_back counter visible in summary; ` +
    `stock=${remainingStock}/${data.startingStock} (unchanged = ACID rollback worked)`,
  );

  check({ remainingStock, startingStock: data.startingStock }, {
    'after ACID: stock unchanged after many rolled-back checkouts': ({ remainingStock, startingStock }) =>
      remainingStock === startingStock,
  });

  sleep(0.1);
}
