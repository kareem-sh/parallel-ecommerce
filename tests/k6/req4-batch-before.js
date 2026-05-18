import http from 'k6/http';
import { check } from 'k6';

export const options = {
  scenarios: {
    report_before: {
      executor: 'constant-vus',
      vus: 5,
      duration: '15s',
    },
  },
};

const baseUrl = __ENV.BASE_URL || 'http://localhost:8000';
const date = __ENV.REPORT_DATE || '2026-05-18';

export default function () {
  const response = http.get(`${baseUrl}/api/before/reports/daily-sales?date=${date}`);

  check(response, {
    'before report computed synchronously': (r) => r.status === 200,
  });
}
