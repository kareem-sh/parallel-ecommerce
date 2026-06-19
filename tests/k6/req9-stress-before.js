import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';
import { baseUrl } from './lib/common.js';
import {
  beforeStressPaths,
  maybeWaitForStressBurst,
  pickStressPath,
  stressScenarioOptions,
} from './lib/stress-routes.js';

const collapseSignals = new Counter('system_collapse_signals');

export const options = {
  scenarios: {
    stress_before: stressScenarioOptions(),
  },
  thresholds: {
    checks: ['rate>0.90'],
    system_collapse_signals: ['count<50'],
    vus_max: ['value>=100'],
  },
};

export default function () {
  maybeWaitForStressBurst();
  const path = pickStressPath(beforeStressPaths);
  const response = http.get(`${baseUrl}${path}`);

  if (response.status === 0 || response.status >= 500) {
    collapseSignals.add(1);
  }

  check(response, {
    '100 simultaneous users: before mixed routes still respond': (r) =>
      r.status === 200 || r.status === 502 || r.status === 503,
    'before still returns version header when healthy': (r) =>
      r.status !== 200 || r.headers['X-Backend-Version'] === 'before',
  });
}

export function teardown() {
  const health = http.get(`${baseUrl}/api/health`);
  const products = http.get(`${baseUrl}/api/before/products?limit=5`);

  check(health, {
    'before: system reachable after stress (no total collapse)': (r) =>
      r.status === 200 || r.status === 503,
  });

  check(products, {
    'before: products still readable after mixed-route stress': (r) =>
      r.status === 200 && Array.isArray(r.json('data')),
  });
}
