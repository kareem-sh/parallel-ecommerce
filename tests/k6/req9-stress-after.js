import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';
import { baseUrl } from './lib/common.js';
import {
  afterStressPaths,
  maybeWaitForStressBurst,
  pickStressPath,
  stressScenarioOptions,
} from './lib/stress-routes.js';

// 503 = capacity guard (graceful). 500/502/0 = real collapse signals.
const graceful503 = new Counter('graceful_capacity_rejections');
const collapseSignals = new Counter('system_collapse_signals');

export const options = {
  scenarios: {
    stress_after: stressScenarioOptions(),
  },
  thresholds: {
    checks: ['rate>0.98'],
    system_collapse_signals: ['count==0'],
    vus_max: ['value>=100'],
  },
};

export function setup() {
  for (const path of afterStressPaths) {
    http.get(`${baseUrl}${path}`);
  }
}

export default function () {
  maybeWaitForStressBurst();
  const path = pickStressPath(afterStressPaths);
  const response = http.get(`${baseUrl}${path}`);

  if (response.status === 503) {
    graceful503.add(1);
  } else if (response.status === 0 || response.status >= 500) {
    collapseSignals.add(1);
  }

  check(response, {
    '100 simultaneous users: after mixed routes respond (200 or controlled 503)': (r) =>
      r.status === 200 || r.status === 503,
    '503 is graceful rejection not collapse': (r) =>
      r.status !== 503 ||
      (r.json('message') != null && r.json('max_capacity') != null),
    'after keeps backend version under stress': (r) =>
      r.status === 503 || r.headers['X-Backend-Version'] === 'after',
  });
}

export function teardown() {
  const health = http.get(`${baseUrl}/api/health`);
  const products = http.get(`${baseUrl}/api/after/products?limit=5`);

  check(health, {
    'system did not collapse: health returns 200 after stress': (r) => r.status === 200,
    'no data loss: database healthy after stress': (r) =>
      r.status === 200 && r.json('checks.database') === true,
    'no data loss: redis healthy after stress': (r) =>
      r.status === 200 && r.json('checks.redis') === true,
  });

  check(products, {
    'no data loss: products still readable after stress': (r) =>
      r.status === 200 && Array.isArray(r.json('data')),
  });
}
