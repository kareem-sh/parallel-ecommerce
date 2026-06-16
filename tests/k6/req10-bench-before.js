import http from 'k6/http';
import { check } from 'k6';
import { Trend } from 'k6/metrics';
import { baseUrl } from './lib/common.js';

const productsMs = new Trend('products_read_ms', true);
const hotProductsMs = new Trend('hot_products_read_ms', true);
const benchmarkMs = new Trend('benchmark_duration_ms', true);

const ROUTES = [
  {
    name: 'products',
    path: '/api/before/products?limit=20',
    trend: productsMs,
  },
  {
    name: 'hot-products',
    path: '/api/before/hot-products?limit=20',
    trend: hotProductsMs,
  },
  {
    name: 'benchmark',
    path: '/api/before/benchmarks/products?limit=20',
    trend: benchmarkMs,
    usePayloadMs: true,
  },
];

export const options = {
  scenarios: {
    bench_before: {
      executor: 'constant-vus',
      vus: 20,
      duration: '15s',
    },
  },
  thresholds: {
    checks: ['rate>0.95'],
    benchmark_duration_ms: ['p(95)>30'],
  },
};

export default function () {
  for (const route of ROUTES) {
    const response = http.get(`${baseUrl}${route.path}`);
    const duration = route.usePayloadMs
      ? response.json('duration_ms') || response.timings.duration
      : response.timings.duration;

    if (duration) {
      route.trend.add(duration);
    }

    check(response, {
      [`before ${route.name} responds`]: (r) => r.status === 200,
    });

    if (route.name === 'benchmark') {
      check(response, {
        'before benchmark exposes bottleneck metadata': (r) =>
          r.headers['X-Benchmark-Bottleneck'] === 'direct-database-scan' &&
          r.json('requirement') === 10,
        'before benchmark is uncached': (r) => r.json('cached') === false,
      });
    }
  }
}
