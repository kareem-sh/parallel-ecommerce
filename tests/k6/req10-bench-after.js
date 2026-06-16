import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';
import { baseUrl } from './lib/common.js';

const productsMs = new Trend('products_read_ms', true);
const hotProductsMs = new Trend('hot_products_read_ms', true);
const benchmarkMs = new Trend('benchmark_duration_ms', true);
const cacheHitRate = new Rate('benchmark_cache_hit_rate');

const ROUTES = [
  {
    name: 'products',
    path: '/api/after/products?limit=20',
    trend: productsMs,
    trackCache: true,
  },
  {
    name: 'hot-products',
    path: '/api/after/hot-products?limit=20',
    trend: hotProductsMs,
    trackCache: true,
  },
  {
    name: 'benchmark',
    path: '/api/after/benchmarks/products?limit=20',
    trend: benchmarkMs,
    trackCache: true,
    usePayloadMs: true,
  },
];

export const options = {
  scenarios: {
    bench_after: {
      executor: 'constant-vus',
      vus: 20,
      duration: '15s',
    },
  },
  thresholds: {
    checks: ['rate>0.95'],
    benchmark_cache_hit_rate: ['rate>0.4'],
    benchmark_duration_ms: ['p(95)<2000'],
  },
};

export function setup() {
  for (const route of ROUTES) {
    http.get(`${baseUrl}${route.path}`);
  }
  sleep(0.5);
}

export default function () {
  for (const route of ROUTES) {
    const response = http.get(`${baseUrl}${route.path}`);
    const hit = response.headers['X-Backend-Cache'] === 'hit';
    const duration = route.usePayloadMs
      ? response.json('duration_ms') || response.timings.duration
      : response.timings.duration;

    if (duration) {
      route.trend.add(duration);
    }

    if (route.trackCache) {
      cacheHitRate.add(hit);
    }

    check(response, {
      [`after ${route.name} responds`]: (r) => r.status === 200 || r.status === 503,
      [`after ${route.name} version`]: (r) =>
        r.status === 503 || r.headers['X-Backend-Version'] === 'after',
    });

    if (route.name === 'benchmark') {
      check(response, {
        'after benchmark exposes optimized bottleneck': (r) =>
          r.status === 503 ||
          (r.headers['X-Benchmark-Bottleneck'] === 'redis-cache' &&
            r.json('requirement') === 10),
      });
    }
  }

  sleep(0.05);
}
