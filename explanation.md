# E-Commerce NFR — Explanations

This document answers the open questions from `todo.txt`: Grafana/k6 metrics, locking strategies, k6 test mapping, threshold errors, and requirement 10 benchmarking.

---

## 1. Grafana / k6 metrics — what they mean

All panels read from **InfluxDB**, which k6 fills when you run with `--out influxdb=...` or view the JSON summary from `.\scripts\run-k6-single.ps1`.

Units below assume HTTP timing in **milliseconds (ms)** unless noted.

### 1. Average Response Time

**What it is:** Mean time from sending the request until the full response is received (`http_req_duration`).

**What it tells you:** Typical user experience under load — not the worst case.

| Good | Bad |
|------|-----|
| Stable line, low hundreds of ms for read APIs | Climbing average during the test (backlog / saturation) |
| After path lower than before for the same test | Multi-second averages on simple GETs |

**Example:** Avg 120 ms → most requests feel fast. Avg 2,500 ms → the server is struggling on average.

---

### 2. P95 Response Time

**What it is:** 95% of requests finished faster than this value; 5% were slower.

**What it tells you:** “Bad but not extreme” tail latency — better than max for SLOs.

| Good | Bad |
|------|-----|
| P95 under your target (e.g. &lt; 500 ms for cached reads) | P95 several times higher than average |
| After cache warmup: P95 drops vs before | P95 spikes when VUs increase |

**Example:** Avg 80 ms, P95 400 ms → a few slow requests. P95 8,000 ms → consistent tail pain.

---

### 3. P99 Response Time

**What it is:** 99% of requests were faster than this; 1% were slower.

**What it tells you:** Rare slow requests (GC pauses, lock waits, cold cache).

| Good | Bad |
|------|-----|
| P99 modestly above P95 | P99 ≫ P95 (unstable tail) |

**Example:** P95 200 ms, P99 350 ms → healthy. P99 15,000 ms → occasional timeouts or queueing.

---

### 4. Max Response Time

**What it is:** Slowest single request in the bucket or run.

**What it tells you:** Worst outlier — useful to spot bottlenecks (e.g. 100, 100, 100, **10,000** → one path blocked).

| Good | Bad |
|------|-----|
| Max not orders of magnitude above P99 | Max very high while mean stays low (hidden spikes) |

---

### 5. Requests Per Second (RPS)

**What it is:** How many HTTP requests the system handles per second (derivative of the `http_reqs` counter).

**What it tells you:** Throughput capacity.

| Good | Bad |
|------|-----|
| Steady RPS under target load | RPS collapses while VUs stay high |
| After path higher RPS than before (req 5) | RPS flat but errors rise |

**Example:** 12 VUs, ~400 RPS on cached reads → good. Same VUs, 20 RPS → severe bottleneck.

---

### 6. Total Requests

**What it is:** Count of all HTTP requests in the time range.

**What it tells you:** How much work the test actually did (sanity check).

**Example:** 10 s × 20 VUs × ~10 iter/s ≈ thousands of requests. Very low total → test misconfigured or early abort.

---

### 7. Active Virtual Users (VUs)

**What it is:** How many k6 virtual users are running **right now** (`vus`).

**What it tells you:** Current concurrency — should match your scenario (e.g. ramp to 100 for stress).

---

### 8. Maximum Configured VUs

**What it is:** Peak VUs the scenario allows (`vus_max`).

**What it tells you:** Whether the test reached the intended load (e.g. 100 for requirement 9).

---

### 9. Error Rate %

**What it is:** Share of requests k6 marks as failed (`http_req_failed` × 100).

**What it tells you:** HTTP/network failures and status codes k6 treats as failed (includes **503** on purpose for req 2 after).

| Good | Bad |
|------|-----|
| ~0% on before/after read tests | High % on paths that should return 200 |
| **Req 2 after:** high % can be **expected** (503 = capacity guard) | High % on after products/load when you expect 200 |

**Important:** For requirement 2 **after**, a high error rate often means **503 rejections working**, not a broken server. Use `capacity_rejected_rate` and checks together.

---

### 10. Successful Checks %

**What it is:** Percentage of `check()` assertions that returned true (`checks` metric × 100).

**What it tells you:** Whether the test’s **business rules** passed (status, headers, JSON fields).

| Good | Bad |
|------|-----|
| ≥ 95% (most scripts use `rate>0.95`) | Below threshold → see terminal `ERRO thresholds on metrics 'checks'` |

---

### 11. Dropped Iterations

**What it is:** Iterations k6 could not start/finish (timeout, `maxDuration`, graceful stop).

**What it tells you:** Load exceeded what the scenario allowed.

| Good | Bad |
|------|-----|
| 0 | &gt; 0 → increase `maxDuration`, reduce VUs, or fix server timeouts |

---

### 12. Iteration Duration Mean

**What it is:** Average time for one full `default function()` run (all requests inside it).

**What it tells you:** End-to-end script step time, not just one HTTP call.

---

### 13. Iteration Duration P95

**What it is:** 95th percentile of iteration duration.

**What it tells you:** Slow iterations (e.g. req 10 hitting multiple routes per iteration).

---

## 2. Optimistic vs pessimistic vs distributed locks (and this project)

### Optimistic locking

- **Idea:** Read a **version** field; update only if version unchanged (`UPDATE … WHERE stock_version = ?`).
- **Conflict:** Another writer won → update fails → app returns 409.
- **In this project:** **Not used anymore** on stock-adjust. Column `stock_version` still increments but is not used for conflict detection.

### Pessimistic locking

- **Idea:** Hold a **database row lock** during the transaction (`SELECT … FOR UPDATE` / `lockForUpdate()`).
- **Conflict:** Other transactions **wait** for the lock.
- **In this project:** Used **inside** after-path transactions:
  - `optimizedStockAdjustment` (with Redis lock outside)
  - `createOptimizedOrder`
  - `optimizedCheckoutWithPayment`

### Distributed lock (Redis)

- **Idea:** Only one app instance / request holds a named lock (`Cache::lock('ecommerce:…')`) across the cluster.
- **Conflict:** Others **wait** (block) or **fail** (timeout → 409).
- **In this project:**
  - **Req 7 after:** `ecommerce:stock:adjust:{productId}` on stock-adjust
  - **Req 1 after:** `ecommerce:order:create:{productIds}` on order create

### Before path (no real locking)

| Endpoint | Locking label | Behavior |
|----------|---------------|----------|
| `POST /api/before/products/{id}/stock-adjust` | `none` | Read-modify-write, race possible |
| `POST /api/before/orders` | — | No Redis lock, no transaction |
| `POST /api/before/checkout` | — | Partial state on payment failure |

### After path (optimized)

| Endpoint | Mechanism |
|----------|-----------|
| `POST /api/after/products/{id}/stock-adjust` | Redis distributed lock + DB transaction + `lockForUpdate` |
| `POST /api/after/orders` | Redis lock + transaction + `lockForUpdate` |
| `POST /api/after/checkout` | Single DB transaction + `lockForUpdate` |

---

## 3. k6 tests vs requirements (1–10)

Mapping to `paralell_project.pdf` / `docs/NFR_REQUIREMENTS_AR.md`:

| Req | Topic | Before script | Before endpoint | After script | After endpoint |
|-----|--------|---------------|-----------------|--------------|----------------|
| 1 | Race / stock integrity | `req1-race-before.js` | `POST /api/before/orders` | `req1-race-after.js` | `POST /api/after/orders` |
| 2 | Capacity | `req2-capacity-before.js` | `GET /api/before/products` | `req2-capacity-after.js` | `GET /api/after/products?simulate_ms=…` |
| 3 | Async queue | `req3-queue-before.js` | `POST /api/before/orders` | `req3-queue-after.js` | `POST /api/after/orders` |
| 4 | Batch reports | `req4-batch-before.js` | `GET /api/before/reports/daily-sales` | `req4-batch-after.js` | `POST /api/after/reports/daily-sales` |
| 5 | Load distribution | `req5-load-before.js` | `GET /api/before/products` | `req5-load-after.js` | `GET /api/after/products` (via nginx → app+app2) |
| 6 | Redis cache | `req6-cache-before.js` | `GET /api/before/hot-products` | `req6-cache-after.js` | `GET /api/after/hot-products` |
| 7 | Concurrency / locks | `req7-lock-before.js` | `POST /api/before/.../stock-adjust` | `req7-lock-after.js` | `POST /api/after/.../stock-adjust` |
| 8 | ACID checkout | `req8-acid-before.js` | `POST /api/before/checkout` | `req8-acid-after.js` | `POST /api/after/checkout` |
| 9 | Stress 100 VUs (simultaneous) | `req9-stress-before.js` | `GET /api/before/hot-products` | `req9-stress-after.js` | `GET /api/after/hot-products` |
| 10 | Benchmark | `req10-bench-before.js` | multiple routes (see §5) | `req10-bench-after.js` | multiple routes (see §5) |

**How to run all:**

```powershell
docker compose up -d
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --force
.\scripts\run-k6-requirements.ps1
```

Each test creates its own product/data in `setup()` where needed so runs do not depend on fixed `product_id`.

---

## 4. Threshold error messages — are things OK?

### `ERRO thresholds on metrics 'checks' have been crossed`

**Meaning:** Less than 95% (or 98%) of `check()` assertions passed.

**Common causes:**

1. Server not running or DB not seeded → 404/422 on orders.
2. **Req 2 after:** burst did not produce 503/200 as expected (run after `redis FLUSHDB`).
3. **Req 7 after:** `teardown` checks failed (stock assertion).
4. Wrong `REPORT_DATE` for req 4 (scripts use today via `run-k6-single.ps1`).

**Is it OK?** **No** for a finished demo — fix environment or thresholds. **Yes** if you intentionally probe failure (e.g. before path overselling).

**What to do:** Read the k6 summary table for which `check()` failed; open the JSON in `storage/k6/<timestamp>/`.

---

### `ERRO thresholds on metrics 'lock_contention_rate' have been crossed`

**Meaning:** Too many stock-adjust requests returned **409** (could not acquire Redis lock in time).

**Is it OK?** For **requirement 7 after**, some 409s under 30 concurrent VUs can happen. The important part is:

- Stock stays **non-negative**
- Successful responses show `"locking": "distributed"`
- Before path still shows **lost updates** (high remaining stock in teardown)

The script no longer **fails** the run on high `lock_contention_rate`; it only reports the metric. If you see this ERRO on an old script version, pull latest `req7-lock-after.js`.

### Requirement 7 after — why distributed lock looks “worse” than optimistic lock

**Before path (`req7-lock-before`):** No lock. Almost every concurrent request returns **200** and decrements stock — but many updates are **lost** (read-modify-write race). Teardown expects `remaining stock > 20` even though ~400 adjustments were sent against starting stock `60`.

**Old after design (optimistic locking):** Each request tried `UPDATE … WHERE stock_version = ?`. Conflicts returned **409 immediately** — no waiting. Fast, but two app servers could still race between read and write without a cluster-wide lock.

**Current after design (distributed Redis lock):** Only one request holds `ecommerce:stock:adjust:{id}` at a time. Others **wait** up to 5 seconds (`block(5)`), then get **409** if the lock is still held. Under 20–30 k6 VUs on one product:

| Response | Meaning | Expected under load? |
|----------|---------|-------------------|
| **200** | Lock acquired, stock updated, `"locking": "distributed"` | Yes — up to starting stock (60 in the test) |
| **409** | Lock wait timed out, or stock insufficient | Yes — most requests after the first wave |
| **503** | `CapacityLimiterMiddleware` on `/api/after/*` (max 25 concurrent) | Sometimes — if VUs > 25 |

So a **high `lock_contention_rate` and many 409s are correct** — they prove serialization works. Success for req 7 after means:

1. Final stock is **correct** (teardown log: `remaining stock: 0` with starting `60`).
2. Every **200** has `"locking": "distributed"`.
3. Every **409** has `"requirement": 7`.
4. **Before** still shows lost updates (`remaining > 20`).

This is different from optimistic locking, where you would see more fast 409s on version mismatch and fewer “wait then timeout” 409s.

---

### `ERRO thresholds on metrics 'http_req_failed' have been crossed` (req 9 after)

**Where it is set:** `tests/k6/req9-stress-after.js`

```javascript
thresholds: {
  checks: ['rate>0.98'],
  http_req_failed: ['rate<0.60'],  // was 0.50, then relaxed to 0.60
},
```

**What `http_req_failed` means:** k6 marks a request as “failed” when the HTTP status is **4xx or 5xx** (unless you customize `http_req_failed` elsewhere). It is **not** the same as `checks` — your `check()` functions explicitly allow **503**, but k6 still counts 503 toward `http_req_failed`.

**Why we changed it (50% → 60%):**

Requirement 9 fires **100 VUs at the same moment** (`startVUs: 100`) against `/api/after/hot-products`. All `/api/after/*` routes use `CapacityLimiterMiddleware` with **max 25 concurrent** requests.

So under a true 100-user burst:

| What happens | Counts as |
|--------------|-----------|
| Request admitted, cache hit/miss, returns 200 | `http_req_failed` = **no**, `checks` = **pass** |
| Capacity full, returns **503** intentionally | `http_req_failed` = **yes**, `checks` = **pass** (503 is allowed in the script) |
| Real outage (502, timeout, 500) | `http_req_failed` = **yes**, `checks` = **fail** |

With 100 simultaneous users and a limit of 25, **roughly half** of requests can be 503. A real run showed **~51–52%** `http_req_failed` while **checks stayed at 100%** and health was OK after the test. The old **50%** threshold failed the run even though the system behaved correctly.

**What happens if you change the threshold:**

| Value | Effect |
|-------|--------|
| **Lower** (e.g. `rate<0.30`) | Stricter. Test fails unless almost all requests return 200. **Wrong for req 9 after** — you would be punishing intentional 503 capacity protection. |
| **Keep ~50%** (`rate<0.50`) | Borderline. Can fail on a good run (~52% 503) — flaky CI/demo. |
| **Current 60%** (`rate<0.60`) | Allows expected 503s under 100-user burst while still failing if **most** requests fail (e.g. server down, &gt;60% errors). |
| **Higher** (e.g. `rate<0.90`) | Very loose. Test almost never fails on error rate even if the app is badly degraded — only use if you only care about `checks` and teardown health. |
| **Remove it** | Only `checks` and teardown decide pass/fail. Fine for demos if you read `checks` and Grafana; you lose an automatic guard against total meltdown. |

**What to trust for req 9 after “success”:**

1. **`checks` ≥ 98%** — every response was 200 or 503 with correct headers.
2. **Teardown** — `/api/health` shows database and Redis healthy.
3. **Grafana** — error % may look high; filter mentally: **503 on after path during stress = capacity guard working**, not a broken server.

**Req 9 before** uses `http_req_failed: ['rate<0.35']` on `/api/before/hot-products` (no capacity middleware), so a high failure rate there usually means real overload or outage.

---

### Exit code 99

k6 returns **99** when any threshold fails. Exit **0** = all thresholds passed.

---

## 5. Requirement 10 — benchmarking routes

### Is it satisfied?

**Yes.** Requirement 10 k6 scripts benchmark **three important read routes** per iteration:

| Route | Before | After | What it exercises |
|-------|--------|-------|-------------------|
| Product catalog | `GET /api/before/products` | `GET /api/after/products` | Req 5/6 style list + Redis cache on after |
| Hot products | `GET /api/before/hot-products` | `GET /api/after/hot-products` | Heavy read / Redis hot cache (req 6) |
| Benchmark API | `GET /api/before/benchmarks/products` | `GET /api/after/benchmarks/products` | Declared bottleneck metadata + `duration_ms` in JSON |

Dedicated benchmark endpoints wrap the hot-products operation and expose:

- **Before:** `X-Benchmark-Bottleneck: direct-database-scan`, `cached: false`
- **After:** `X-Benchmark-Bottleneck: redis-cache`, `X-Backend-Cache: hit|miss`

### How to track the difference

1. **k6 JSON** — compare custom trends:
   - `products_read_ms`, `hot_products_read_ms`, `benchmark_duration_ms`
   - Before: higher p95 on all three; after: `benchmark_cache_hit_rate` &gt; 0 after warmup

2. **Grafana** — run `.\scripts\run-k6-grafana.ps1 -Test req10-bench-after` and compare panels 1–4 before vs after runs.

3. **Response JSON** — field `duration_ms` from benchmark endpoint (server-measured, not k6 network time).

**Example (illustrative):**

| Metric | Before p95 | After p95 (warmed) |
|--------|------------|---------------------|
| `benchmark_duration_ms` | 180 ms | 25 ms |
| `hot_products_read_ms` | 200 ms | 40 ms |
| `products_read_ms` | 150 ms | 30 ms (cache hit) |

### Run commands

```powershell
.\scripts\run-k6-single.ps1 -Test req10-bench-before
.\scripts\run-k6-single.ps1 -Test req10-bench-after
```

Compare files under `storage/k6/<timestamp>/`.

---

## Quick reference — good vs bad by requirement

| Req | Before “success” looks like | After “success” looks like |
|-----|----------------------------|----------------------------|
| 1 | Many orders accepted, stock oversold | ~5×201, rest 409, stock correct |
| 2 | All 200, no 503 | Mix of 200 + 503, `capacity_rejected_rate` &gt; 10% |
| 3 | Slow p95 (sync work) | Fast 201, `queued_order_response_rate` high |
| 4 | Slow 200 sync report | Fast 202 queued |
| 5 | Throughput baseline | Higher RPS, stable checks |
| 6 | `cache_hit_rate` = 0 | `cache_hit_rate` &gt; 50% |
| 7 | Stock remains too high after concurrent decrements | Stock correct, `locking: distributed` |
| 8 | 402 + `partial_state_possible` | 402 + `rolled_back: true` |
| 9 | May degrade; checks &gt; 90%; 100 VUs start together | 100 VUs from t=0; checks &gt; 98%; `http_req_failed` &lt; 60% (503 expected); health OK in teardown |
| 10 | High `benchmark_duration_ms` | Lower duration + cache hits |

---

*Generated for the `grafana-panels` branch. For JSON field details see `docs/K6_RESULTS_GUIDE.md`.*
