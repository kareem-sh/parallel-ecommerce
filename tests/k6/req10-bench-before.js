import http from 'k6/http';
import { check } from 'k6';
import { Trend } from 'k6/metrics';
import { baseUrl } from './lib/common.js';

const benchmarkMs = new Trend('benchmark_duration_ms', true);

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
    benchmark_duration_ms: ['p(95)>50'],
  },
};

export default function () {
  const response = http.get(`${baseUrl}/api/before/benchmarks/products?limit=20`);
  const payloadMs = response.json('duration_ms');

  if (payloadMs) {
    benchmarkMs.add(payloadMs);
  }

  check(response, {
    'before benchmark responds': (r) => r.status === 200,
    'before exposes bottleneck metadata': (r) =>
      r.headers['X-Benchmark-Bottleneck'] === 'direct-database-scan' &&
      r.json('requirement') === 10,
    'before benchmark is uncached': (r) => r.json('cached') === false,
  });
}
