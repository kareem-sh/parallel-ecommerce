import http from 'k6/http';
import { check } from 'k6';
import { baseUrl, reportDate } from './lib/common.js';

export const options = {
  scenarios: {
    report_before: {
      executor: 'constant-vus',
      vus: 5,
      duration: '15s',
    },
  },
  thresholds: {
    checks: ['rate>0.95'],
  },
};

const date = reportDate();

export default function () {
  const response = http.get(`${baseUrl}/api/before/reports/daily-sales?date=${date}`);

  check(response, {
    'before report computed synchronously': (r) => r.status === 200,
  });
}
