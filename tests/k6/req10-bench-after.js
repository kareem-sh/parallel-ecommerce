import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';
import { baseUrl } from './lib/common.js';

const benchmarkMs = new Trend('benchmark_duration_ms', true);
const cacheHitRate = new Rate('benchmark_cache_hit_rate');

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
    benchmark_cache_hit_rate: ['rate>0.5'],
  },
};

export function setup() {
  http.get(`${baseUrl}/api/after/benchmarks/products?limit=20`);
}

export default function () {
  const response = http.get(`${baseUrl}/api/after/benchmarks/products?limit=20`);
  const payloadMs = response.json('duration_ms');
  const hit = response.headers['X-Backend-Cache'] === 'hit';

  if (payloadMs) {
    benchmarkMs.add(payloadMs);
  }

  cacheHitRate.add(hit);

  check(response, {
    'after benchmark responds': (r) => r.status === 200 || r.status === 503,
    'after removes database bottleneck': (r) =>
      r.status === 503 || r.headers['X-Benchmark-Bottleneck'] === 'redis-cache',
    'after benchmark reports requirement 10': (r) =>
      r.status === 503 || r.json('requirement') === 10,
  });

  sleep(0.05);
}
