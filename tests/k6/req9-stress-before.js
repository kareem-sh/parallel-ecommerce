import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';
import { baseUrl } from './lib/common.js';

const collapseSignals = new Counter('system_collapse_signals');

export const options = {
  scenarios: {
    stress_before: {
      executor: 'ramping-vus',
      startVUs: 100,
      stages: [
        { duration: '30s', target: 100 },
      ],
      gracefulRampDown: '0s',
    },
  },
  thresholds: {
    checks: ['rate>0.90'],
    system_collapse_signals: ['count<50'],
    vus_max: ['value>=100'],
  },
};

export default function () {
  const response = http.get(`${baseUrl}/api/before/hot-products?limit=20`);

  if (response.status === 0 || response.status >= 500) {
    collapseSignals.add(1);
  }

  check(response, {
    '100 simultaneous users: before path still responds': (r) =>
      r.status === 200 || r.status === 502 || r.status === 503,
    'before still returns version header when healthy': (r) =>
      r.status !== 200 || r.headers['X-Backend-Version'] === 'before',
  });
}

export function teardown() {
  const health = http.get(`${baseUrl}/api/health`);

  check(health, {
    'before: system reachable after stress (no total collapse)': (r) =>
      r.status === 200 || r.status === 503,
  });
}
