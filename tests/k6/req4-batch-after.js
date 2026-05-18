import http from 'k6/http';
import { check } from 'k6';

export const options = {
  scenarios: {
    report_after: {
      executor: 'constant-vus',
      vus: 5,
      duration: '15s',
    },
  },
  thresholds: {
    http_req_duration: ['p(95)<1000'],
  },
};

const baseUrl = __ENV.BASE_URL || 'http://localhost:8000';
const date = __ENV.REPORT_DATE || '2026-05-18';

export default function () {
  const response = http.post(`${baseUrl}/api/after/reports/daily-sales?date=${date}`);

  check(response, {
    'after report queued quickly': (r) => r.status === 202,
  });
}
