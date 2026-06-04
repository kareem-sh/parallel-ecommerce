import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';
import { baseUrl } from './lib/common.js';

const cacheHits = new Counter('redis_cache_hits');
const cacheHitRate = new Rate('cache_hit_rate');
const readDuration = new Trend('hot_products_read_ms', true);

export const options = {
  scenarios: {
    cache_after: {
      executor: 'constant-vus',
      vus: 25,
      duration: '15s',
    },
  },
  thresholds: {
    checks: ['rate>0.95'],
    cache_hit_rate: ['rate>0.5'],
    redis_cache_hits: ['count>10'],
  },
};

export function setup() {
  const warmup = http.get(`${baseUrl}/api/after/hot-products?limit=20`);

  check(warmup, {
    'cache warmup succeeds': (r) => r.status === 200,
  });
}

export default function () {
  const response = http.get(`${baseUrl}/api/after/hot-products?limit=20`);
  const hit = response.headers['X-Backend-Cache'] === 'hit';

  if (hit) {
    cacheHits.add(1);
  }

  cacheHitRate.add(hit);
  readDuration.add(response.timings.duration);

  check(response, {
    'after hot-products responds': (r) => r.status === 200 || r.status === 503,
    'after uses redis cache layer': (r) =>
      r.status === 503 || r.headers['X-Backend-Version'] === 'after',
    'after cache header present': (r) =>
      r.status === 503 ||
      r.headers['X-Backend-Cache'] === 'hit' ||
      r.headers['X-Backend-Cache'] === 'miss',
  });

  sleep(0.05);
}
