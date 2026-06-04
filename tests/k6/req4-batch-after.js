import http from 'k6/http';
import { check } from 'k6';
import { Rate } from 'k6/metrics';
import { baseUrl, reportDate } from './lib/common.js';

const reportQueued = new Rate('report_queued_rate');

export const options = {
  scenarios: {
    report_after: {
      executor: 'constant-vus',
      vus: 5,
      duration: '15s',
    },
  },
  thresholds: {
    checks: ['rate>0.95'],
    http_req_duration: ['p(95)<1000'],
  },
};

const date = reportDate();

export default function () {
  const response = http.post(`${baseUrl}/api/after/reports/daily-sales?date=${date}`);
  reportQueued.add(response.status === 202);

  check(response, {
    'after report queued quickly': (r) => r.status === 202,
  });
}
