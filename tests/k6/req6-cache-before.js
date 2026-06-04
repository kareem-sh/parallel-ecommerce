import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';
import { baseUrl } from './lib/common.js';

const cacheHitRate = new Rate('cache_hit_rate');
const readDuration = new Trend('hot_products_read_ms', true);

export const options = {
  scenarios: {
    cache_before: {
      executor: 'constant-vus',
      vus: 25,
      duration: '15s',
    },
  },
  thresholds: {
    checks: ['rate>0.95'],
    cache_hit_rate: ['rate==0'],
  },
};

export default function () {
  const response = http.get(`${baseUrl}/api/before/hot-products?limit=20`);
  const hit = response.headers['X-Backend-Cache'] === 'hit';

  cacheHitRate.add(hit);
  readDuration.add(response.timings.duration);

  check(response, {
    'before hot-products responds': (r) => r.status === 200,
    'before exposes no distributed cache': (r) =>
      r.headers['X-Backend-Version'] === 'before' &&
      (r.headers['X-Backend-Cache'] === 'none' || r.headers['X-Backend-Cache'] === undefined),
    'before payload marks uncached': (r) => r.json('cached') === false,
  });

  sleep(0.05);
}
