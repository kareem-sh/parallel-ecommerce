# Requirement 9 — Stress Testing (100 Concurrent Users)

Guide for presentation: **before vs after**, k6 code walkthrough, **exact terminal results**, how to compare, how to explain stability, and **req 9 vs req 10**.

---

## 1. What requirement 9 proves (assignment)

> Stress test: serve **at least 100 simultaneous users** without **collapse** (انهيار) or **data loss** (فقدان بيانات).

| Pass | Fail |
|------|------|
| Server responds; checks pass | 500 / 502 / timeouts (`system_collapse_signals`) |
| Health + DB + Redis OK after test | Health down or data unreadable |
| **503 with JSON capacity message** | Treat 503 as **graceful rejection**, not collapse |

---

## 2. k6 code — before (`req9-stress-before.js`)

Each virtual user picks **one random read route** per iteration from:

- `GET /api/before/products?limit=20`
- `GET /api/before/hot-products?limit=20`
- `GET /api/before/orders?limit=10`

```javascript
import { beforeStressPaths, pickStressPath } from './lib/stress-routes.js';

export default function () {
  const path = pickStressPath(beforeStressPaths);
  const response = http.get(`${baseUrl}${path}`);
  // collapseSignals on 500+; checks allow 200/502/503
}
```

**Load pattern:** Terminal shows `100/100 VUs` from the start — proves **simultaneous** load.

**Nginx:** `/api/before/*` → **single `app` container** only (no load balancing).

**Routes:** No Redis cache, no capacity middleware — always DB work on reads.

---

## 3. k6 code — after (`req9-stress-after.js`)

Same **mixed read routes** (random pick per iteration):

- `GET /api/after/products?limit=20`
- `GET /api/after/hot-products?limit=20`
- `GET /api/after/orders?limit=10`

```javascript
import { afterStressPaths, pickStressPath } from './lib/stress-routes.js';

export function setup() {
  for (const path of afterStressPaths) {
    http.get(`${baseUrl}${path}`);  // warm Redis cache on all routes
  }
}

export default function () {
  const path = pickStressPath(afterStressPaths);
  const response = http.get(`${baseUrl}${path}`);
  // graceful503 on 503; collapseSignals on 500+
}
```

**Nginx:** `/api/after/*` → **load balanced** across `app` + `app2` (`least_conn`).

**Optimizations under test:** Redis cache (req 6) + capacity limiter max **25** on `/api/after/*` (req 2).

**Why `http_req_failed` can be > 0 on after:** k6 counts **503 as failed HTTP**, but **checks** still pass — by design.

---

## 4. Exact terminal results

> **Note:** Results below are from an earlier **single-route** run. After switching to **mixed routes** + nginx split, re-run the tests and replace this section.

Run date: **2026-06-19**

```powershell
docker compose exec app2 php artisan package:discover --ansi
.\scripts\run-k6-single.ps1 -Test req9-stress-before
.\scripts\run-k6-single.ps1 -Test req9-stress-after
```

### req9-stress-before — terminal output

```
running (0m01.0s), 100/100 VUs, 9 complete and 0 interrupted iterations
...
running (0m30.0s), 100/100 VUs, 1239 complete and 0 interrupted iterations
stress_before   [ 100% ] 100/100 VUs  30.0s/30.0s

     ✓ 100 simultaneous users: before path still responds
     ✓ before still returns version header when healthy

     █ teardown

       ✓ before: system reachable after stress (no total collapse)

   ✓ checks.........................: 100.00% ✓ 2679      ✗ 0
     data_received..................: 1.1 MB  36 kB/s
     data_sent......................: 142 kB  4.6 kB/s
     http_req_blocked...............: avg=1.62ms   min=412ns   med=2.55µs  max=47.97ms p(90)=6.37µs   p(95)=2.23ms
     http_req_connecting............: avg=444.55µs min=0s      med=0s      max=44.83ms p(90)=0s       p(95)=148.41µs
     http_req_duration..............: avg=2.26s    min=60.1ms  med=2.15s   max=10.02s  p(90)=2.97s    p(95)=4.52s
       { expected_response:true }...: avg=2.26s    min=60.1ms  med=2.15s   max=10.02s  p(90)=2.97s    p(95)=4.52s
     http_req_failed................: 0.00%   ✓ 0         ✗ 1340
     http_req_receiving.............: avg=107.42µs min=10.98µs med=52.22µs max=9.32ms  p(90)=172.69µs p(95)=262.25µs
     http_req_sending...............: avg=96.38µs  min=2.98µs  med=12.45µs max=43.94ms p(90)=46.26µs  p(95)=104.76µs
     http_req_tls_handshaking.......: avg=0s       min=0s      med=0s      max=0s      p(90)=0s       p(95)=0s
     http_req_waiting...............: avg=2.26s    min=59.78ms med=2.15s   max=10.02s  p(90)=2.97s    p(95)=4.52s
     http_reqs......................: 1340    43.401897/s
     iteration_duration.............: avg=2.26s    min=60.81ms med=2.15s   max=10.07s  p(90)=2.97s    p(95)=4.52s
     iterations.....................: 1339    43.369508/s
   ✓ system_collapse_signals........: 0       0/s
     vus............................: 100     min=100     max=100
   ✓ vus_max........................: 100     min=100     max=100

running (0m30.9s), 000/100 VUs, 1339 complete and 0 interrupted iterations
stress_before ✓ [ 100% ] 000/100 VUs  30s
Summary saved to storage/k6/20260619-220014/req9-stress-before.json
```

### req9-stress-after — terminal output

```
running (0m01.0s), 100/100 VUs, 31 complete and 0 interrupted iterations
...
running (0m30.0s), 100/100 VUs, 1931 complete and 0 interrupted iterations
stress_after   [  99% ] 100/100 VUs  29.8s/30.0s

     ✓ 100 simultaneous users: server responds (200 or controlled 503)
     ✓ 503 is graceful rejection not collapse
     ✓ after keeps backend version under stress

     █ setup

     █ teardown

       ✓ system did not collapse: health returns 200 after stress
       ✓ no data loss: database healthy after stress
       ✓ no data loss: redis healthy after stress
       ✓ no data loss: products still readable after stress

   ✓ checks.........................: 100.00% ✓ 6097      ✗ 0
     data_received..................: 1.8 MB  55 kB/s
     data_sent......................: 214 kB  6.7 kB/s
     graceful_capacity_rejections...: 449     14.163098/s
     http_req_blocked...............: avg=370.48µs min=397ns    med=2.26µs  max=57.33ms p(90)=4.75µs   p(95)=66.61µs
     http_req_connecting............: avg=364.77µs min=0s       med=0s      max=57.31ms p(90)=0s       p(95)=12.42µs
     http_req_duration..............: avg=1.52s    min=59.22ms  med=1.17s   max=20.46s  p(90)=2.05s    p(95)=2.87s
       { expected_response:true }...: avg=1.5s     min=59.22ms  med=1.17s   max=20.46s  p(90)=2.05s    p(95)=2.3s
     http_req_failed................: 22.07%  ✓ 449       ✗ 1585
     http_req_receiving.............: avg=82.91µs  min=9.81µs   med=43.35µs max=7.9ms   p(90)=130.52µs p(95)=191.94µs
     http_req_sending...............: avg=73.96µs  min=2.49µs   med=10.72µs max=56.83ms p(90)=25.05µs  p(95)=47.53µs
     http_req_tls_handshaking.......: avg=0s       min=0s       med=0s      max=0s      p(90)=0s       p(95)=0s
     http_req_waiting...............: avg=1.52s    min=59.02ms  med=1.17s   max=20.46s  p(90)=2.05s    p(95)=2.87s
     http_reqs......................: 2034    64.15978/s
     iteration_duration.............: avg=1.52s    min=164.83ms med=1.17s   max=20.49s  p(90)=2.05s    p(95)=2.87s
     iterations.....................: 2031    64.065149/s
   ✓ system_collapse_signals........: 0       0/s
     vus............................: 100     min=100     max=100
   ✓ vus_max........................: 100     min=100     max=100

running (0m31.7s), 000/100 VUs, 2031 complete and 0 interrupted iterations
stress_after ✓ [ 100% ] 000/100 VUs  30s
Summary saved to storage/k6/20260619-220125/req9-stress-after.json
```

---

## 5. Side-by-side comparison table

| Metric | Before | After | Interpretation |
|--------|--------|-------|----------------|
| **100 VUs at t=0** | ✓ `100/100 VUs` | ✓ `100/100 VUs` | Assignment requirement met |
| **checks** | **100%** (2679/2679) | **100%** (6097/6097) | Business rules pass |
| **system_collapse_signals** | **0** | **0** | No real server collapse |
| **http_req_duration avg** | **2.26 s** | **1.52 s** | After ~33% faster |
| **http_req_duration p95** | **4.52 s** | **2.87 s** | Tail latency improved |
| **http_req_failed** | **0%** | **22.07%** | After: mostly **503** (graceful) |
| **graceful_capacity_rejections** | N/A | **449** | Capacity guard working |
| **http_reqs (throughput)** | 1340 (~43/s) | 2034 (~64/s) | More work completed in 30s |
| **Teardown** | health reachable | health + DB + Redis + products OK | **No data loss** |

---

## 6. How to explain the improvement to someone

**One sentence:**  
> Under 100 simultaneous users for 30 seconds, the optimized path stays **stable** (zero collapse signals, 100% checks, data intact), responds **faster on average**, handles **more throughput**, and uses **503** to reject excess load safely instead of crashing.

**Key phrases for your professor:**

1. **“100 users at the same moment”** — show terminal line `100/100 VUs` at second 1.
2. **“503 is not collapse”** — point to `graceful_capacity_rejections: 449` and teardown checks passing.
3. **“No data loss”** — after teardown verifies health, MySQL, Redis, and products list.
4. **“Before still survives but degrades”** — 0% failures but **2.26 s average** and **4.52 s p95** latency.

---

## 7. Requirement 9 vs Requirement 10 (k6 tests)

Both use before/after scripts, but they answer **different questions**.

| | **Req 9 — Stress** | **Req 10 — Benchmark** |
|---|-------------------|------------------------|
| **Question** | Does the system **survive** 100 users at once? | Which **bottleneck** was removed and how fast are reads **per route**? |
| **Scripts** | `req9-stress-before.js` / `req9-stress-after.js` | `req10-bench-before.js` / `req10-bench-after.js` |
| **VUs** | **100** simultaneous | **20** steady |
| **Duration** | **30 s** burst | **15 s** steady |
| **Executor** | `ramping-vus`, `startVUs: 100` | `constant-vus`, 20 |
| **Routes per iteration** | **3 reads** (random: products, hot-products, orders) | **3 reads + benchmark** (req 10) |
| **Nginx routing** | `/api/before/*` → **app only** | `/api/after/*` → **app + app2** |
| **Main endpoint** | `/api/.../hot-products` | All three read endpoints |
| **Success focus** | No collapse, no data loss, checks pass | Latency trends + cache hit rate + bottleneck headers |
| **Custom metrics** | `system_collapse_signals`, `graceful_capacity_rejections` | `products_read_ms`, `hot_products_read_ms`, `benchmark_duration_ms`, `benchmark_cache_hit_rate` |
| **503 on after** | Expected under overload | Allowed; benchmark checks cache/bottleneck metadata |
| **Threshold example (before)** | `system_collapse_signals < 50` | `benchmark_duration_ms p95 > 30` (proves slowness) |
| **Threshold example (after)** | `system_collapse_signals == 0` | `benchmark_cache_hit_rate > 0.4`, `benchmark_duration_ms p95 < 2000` |
| **setup/teardown** | After: warm cache + full health/data teardown | After: warm all 3 routes in setup |

**Simple analogy:**

- **Req 9** = crash test: “Can the car handle 100 passengers jumping in at once without the engine dying?”
- **Req 10** = performance lab: “How long does each dashboard read take before vs after Redis, and what does the benchmark API say the bottleneck is?”

**When to show which in a demo:**

- Professor asks about **stability / 100 users / collapse** → **req 9**
- Professor asks about **optimization proof / cache / bottleneck detection** → **req 10**

---

## 8. How to compare results yourself

### Req 9 — compare these k6 lines

| Line | Before | After | Look for |
|------|--------|-------|----------|
| `checks` | ~100% | ~100% | Must pass |
| `system_collapse_signals` | 0 | 0 | Must stay 0 on after |
| `http_req_duration` avg / p95 | higher | lower | Improvement story |
| `http_req_failed` | ~0% | may be >0% | Explain 503 on after |
| `graceful_capacity_rejections` | — | count > 0 | Capacity protection |
| `vus_max` | 100 | 100 | Config proof |

### Grafana (optional)

```powershell
docker compose up -d grafana influxdb
.\scripts\run-k6-grafana.ps1 -Test req9-stress-before
.\scripts\run-k6-grafana.ps1 -Test req9-stress-after
```

Open **http://localhost:3000** → **NFR → k6 NFR Overview**  
Key panels: **7** (VUs jump to 100), **1–2** (latency), **9** (error rate — explain 503), **10** (checks %).

---

## 9. Run commands

```powershell
docker compose exec app2 php artisan package:discover --ansi
.\scripts\run-k6-single.ps1 -Test req9-stress-before
.\scripts\run-k6-single.ps1 -Test req9-stress-after

# After stress — prove system alive
curl.exe http://localhost:8000/api/health
curl.exe "http://localhost:8000/api/after/products?limit=5"
```

---

*Results captured 2026-06-19 from live k6 runs. JSON: `storage/k6/20260619-220014/` and `220125/`.*
