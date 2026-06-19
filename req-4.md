# Requirement 4 — Batch Processing (Daily Sales Report)

Guide for presentation: **before vs after**, k6 code walkthrough, **exact terminal results**, and how to explain the improvement.

---

## 1. What requirement 4 proves

| Before | After |
|--------|-------|
| User waits while the server loads **all orders** and computes the report **inside the HTTP request** | User gets **202 Accepted** immediately; a **worker** processes orders in **chunks of 100** |
| Blocks a PHP worker for the full report time | Frees the web worker; batch runs in background on Redis queue `reports` |

**Endpoints**

- Before: `GET /api/before/reports/daily-sales?date=YYYY-MM-DD` → **200**
- After queue: `POST /api/after/reports/daily-sales?date=YYYY-MM-DD` → **202**
- After status: `GET /api/after/reports/daily-sales?date=YYYY-MM-DD` → `ready: true` + summary

**Demo data:** `DailySalesReportSeeder` — **1500 orders** for today (UTC), **15 chunks** (1500 ÷ 100).

---

## 2. k6 code — before (`req4-batch-before.js`)

```javascript
export const options = {
  scenarios: {
    report_before: {
      executor: 'constant-vus',   // steady load
      vus: 5,                     // 5 users at once
      duration: '15s',
    },
  },
  thresholds: {
    checks: ['rate>0.95'],        // 95%+ checks must pass
  },
};

export default function () {
  const response = http.get(
    `${baseUrl}/api/before/reports/daily-sales?date=${date}`
  );

  check(response, {
    'before report computed synchronously': (r) => r.status === 200,
  });
}
```

**What it measures:** Every request must return **200** — meaning the user **waited** for the full synchronous report (1500 orders loaded into memory).

**What it does NOT measure:** Whether the report numbers are correct (that is covered by PHPUnit / manual GET).

---

## 3. k6 code — after (`req4-batch-after.js`)

```javascript
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
    http_req_duration: ['p(95)<1000'],   // 95th percentile under 1 second
  },
};

export default function () {
  const response = http.post(
    `${baseUrl}/api/after/reports/daily-sales?date=${date}`
  );
  reportQueued.add(response.status === 202);

  check(response, {
    'after report queued quickly': (r) => r.status === 202,
  });
}
```

**What it measures:** Every request must return **202** fast — the API only **queues** the job; it does not wait for 1500 orders to be processed.

**Custom metric:** `report_queued_rate` — percentage of POSTs that got 202.

**Important for demo:** k6 hits **POST only**. The worker finishing is proven separately via `GET /api/after/reports/daily-sales` (`chunks_processed: 15`).

---

## 4. Exact terminal results (1500 orders, no artificial delay)

Run date: **2026-06-19**  
Commands:

```powershell
docker compose exec app php artisan db:seed --class=DailySalesReportSeeder --force
.\scripts\run-k6-single.ps1 -Test req4-batch-before
.\scripts\run-k6-single.ps1 -Test req4-batch-after
```

### req4-batch-before — terminal output

```
     ✓ before report computed synchronously

   ✓ checks.........................: 100.00% ✓ 200       ✗ 0
     data_received..................: 111 kB  7.3 kB/s
     data_sent......................: 24 kB   1.6 kB/s
     http_req_blocked...............: avg=111.28µs min=1.45µs   med=5.11µs   max=4.92ms   p(90)=8.68µs   p(95)=12.31µs
     http_req_connecting............: avg=25.06µs  min=0s       med=0s       max=1.85ms   p(90)=0s       p(95)=0s
     http_req_duration..............: avg=377.75ms min=191.17ms med=327.18ms max=1.29s    p(90)=542.48ms p(95)=781.44ms
       { expected_response:true }...: avg=377.75ms min=191.17ms med=327.18ms max=1.29s    p(90)=542.48ms p(95)=781.44ms
     http_req_failed................: 0.00%   ✓ 0         ✗ 200
     http_req_receiving.............: avg=141.09µs min=33.54µs  med=139.87µs max=437.81µs p(90)=198.57µs p(95)=245.39µs
     http_req_sending...............: avg=38.16µs  min=5.84µs   med=25.66µs  max=263.56µs p(90)=67.38µs  p(95)=81.13µs
     http_req_tls_handshaking.......: avg=0s       min=0s       med=0s       max=0s       p(90)=0s       p(95)=0s
     http_req_waiting...............: avg=377.57ms min=190.99ms med=327.04ms max=1.29s    p(90)=542.4ms  p(95)=781.24ms
     http_reqs......................: 200     13.135989/s
     iteration_duration.............: avg=377.75ms min=191.43ms med=327.39ms max=1.29s    p(90)=537.91ms p(95)=781.66ms
     iterations.....................: 200     13.135989/s
     vus............................: 5       min=5       max=5
     vus_max........................: 5       min=5       max=5

running (15.2s), 0/5 VUs, 200 complete and 0 interrupted iterations
report_before ✓ [ 100% ] 5 VUs  15s
Summary saved to storage/k6/20260619-215120/req4-batch-before.json
```

### req4-batch-after — terminal output

```
     ✓ after report queued quickly

   ✓ checks.........................: 100.00% ✓ 734       ✗ 0
     data_received..................: 349 kB  23 kB/s
     data_sent......................: 102 kB  6.8 kB/s
     http_req_blocked...............: avg=18.96µs  min=1.89µs  med=5.21µs   max=2.33ms   p(90)=7.68µs   p(95)=9.62µs
     http_req_connecting............: avg=3.29µs   min=0s      med=0s       max=552.43µs p(90)=0s       p(95)=0s
   ✓ http_req_duration..............: avg=102.3ms  min=50.08ms med=94.96ms  max=315.91ms p(90)=141.76ms p(95)=163.07ms
       { expected_response:true }...: avg=102.3ms  min=50.08ms med=94.96ms  max=315.91ms p(90)=141.76ms p(95)=163.07ms
     http_req_failed................: 0.00%   ✓ 0         ✗ 734
     http_req_receiving.............: avg=164.95µs min=37.63µs med=140.26µs max=2.76ms   p(90)=250.87µs p(95)=292.13µs
     http_req_sending...............: avg=38.97µs  min=7.58µs  med=24.69µs  max=898µs    p(90)=65.29µs  p(95)=77.44µs
     http_req_tls_handshaking.......: avg=0s       min=0s      med=0s       max=0s       p(90)=0s       p(95)=0s
     http_req_waiting...............: avg=102.09ms min=50.01ms med=94.81ms  max=314.76ms p(90)=141.58ms p(95)=162.84ms
     http_reqs......................: 734     48.716256/s
     iteration_duration.............: avg=102.56ms min=50.29ms med=95.17ms  max=316.15ms p(90)=142.02ms p(95)=163.38ms
     iterations.....................: 734     48.716256/s
     report_queued_rate.............: 100.00% ✓ 734       ✗ 0
     vus............................: 5       min=5       max=5
     vus_max........................: 5       min=5       max=5

running (15.1s), 0/5 VUs, 734 complete and 0 interrupted iterations
report_after ✓ [ 100% ] 5 VUs  15s
Summary saved to storage/k6/20260619-215142/req4-batch-after.json
```

---

## 5. Side-by-side comparison table

| Metric | Before (GET, sync) | After (POST, async) | Improved? |
|--------|-------------------|---------------------|-----------|
| **checks** | 100% (200/200) | 100% (734/734) | Same reliability |
| **HTTP status expected** | 200 | 202 | Different by design |
| **http_req_duration avg** | **377.75 ms** | **102.3 ms** | **~3.7× faster** |
| **http_req_duration p95** | **781.44 ms** | **163.07 ms** | **~4.8× faster** |
| **http_req_failed** | 0% | 0% | No errors |
| **Throughput (http_reqs/s)** | 13.1 | 48.7 | **~3.7× more requests** |
| **User waits for batch?** | Yes — every GET | No — only queues | Core NFR win |
| **Work location** | PHP-FPM request | Worker + Redis queue | Offloaded |

---

## 6. How to explain the improvement to someone

**One sentence:**  
> Requirement 4 moves daily sales reporting from a **blocking HTTP computation** to a **background batch job**, so the API responds in ~100 ms instead of ~380 ms while still processing 1500 orders in 15 chunks.

**Three talking points:**

1. **Before:** 5 users each trigger a GET that loads 1500 orders + items into memory. Average wait **378 ms**, tail **781 ms**. PHP workers stay busy.
2. **After:** Same 5 users POST the report request. Server returns **202 in ~102 ms**. Actual summation runs in the **worker** (`BuildDailySalesSummaryJob`, `chunkById(100)`).
3. **Proof of batching:** `GET /api/after/reports/daily-sales` shows `orders_count: 1500`, `chunks_processed: 15`.

**If they ask “does the after path still work when other traffic arrives?”**  
Yes — POST only enqueues. Orders, products, health, etc. keep using other PHP workers while the **worker** container drains the `reports` queue.

**`BuildDailySalesSummaryCommand.php`:** CLI wrapper for the same job (`sales:summarize-daily {date} --sync`). Same logic as the API, useful for tests and manual runs.

---

## 7. How to compare results yourself

1. Run both tests back-to-back with the same seed data.
2. Compare these lines from the k6 summary:
   - `checks` — must be near 100%
   - `http_req_duration` **avg** and **p(95)**
   - `http_req_failed` — should be 0% for req 4
   - After only: `report_queued_rate` — should be 100%
3. Open JSON exports under `storage/k6/<timestamp>/`.
4. Optional Grafana: `.\scripts\run-k6-grafana.ps1 -Test req4-batch-before` then after — dashboard **NFR → k6 NFR Overview**, panels 1–2 (latency), 5 (RPS), 10 (checks).

---

## 8. Run commands

```powershell
# Seed 1500 orders for today
docker compose exec app php artisan db:seed --class=DailySalesReportSeeder --force

# Ensure app2 bootstrap cache (after container recreate)
docker compose exec app2 php artisan package:discover --ansi

# k6 tests
.\scripts\run-k6-single.ps1 -Test req4-batch-before
.\scripts\run-k6-single.ps1 -Test req4-batch-after

# Verify worker finished
curl.exe "http://localhost:8000/api/after/reports/daily-sales?date=2026-06-19"
```

---

*Results captured 2026-06-19 with 1500 seeded orders, `usleep` removed from before controller.*
