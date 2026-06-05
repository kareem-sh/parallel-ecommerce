# Full Demo Flow: Before vs After

This is the file to use when you present the project. It shows how to run the backend, seed data, then run an old test and a new test for each required non-functional requirement.

## 0. Start Everything

Run the full stack:

```bash
docker compose up --build -d nginx app app2 worker mysql redis
```

Check services:

```bash
docker compose ps
curl http://localhost:8000/api/health
```

Run migrations and seed demo data:

```bash
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --force
```

Seed data includes products and daily orders so the batch report has data to process.

## 0.1 Infrastructure: Nginx & PHP-FPM

Two Docker config files sit **outside** Laravel but are required for several NFR demos to behave correctly under load. Without them, k6 tests can time out or pass for the wrong reason (queueing at PHP-FPM instead of intentional application-level rejection or load balancing).

### `docker/nginx.conf` — Requirement 5 (Load Distribution)

Nginx is the single public entry point (`localhost:8000`). It defines an upstream pool with **two** PHP-FPM backends:

```nginx
upstream ecommerce_backend {
    least_conn;
    server app:9000;
    server app2:9000;
}
```

| Setting | Role in the project |
|--------|---------------------|
| **Two servers (`app`, `app2`)** | Before path uses one logical backend; after path runs two identical Laravel containers so traffic can be spread across instances. |
| **`least_conn`** | Sends each new request to the worker with the **fewest active connections**. E-commerce requests have uneven duration (cache hit vs miss, orders vs reads), so this is more stable than round-robin for Requirement 5. |
| **`fastcgi_pass ecommerce_backend`** | All `/api/*` PHP requests go through the pool, not a single container. |
| **`fastcgi_keep_conn on`** | Reuses connections to PHP-FPM under sustained k6 load (`req5-load-after`, `req9-stress-after`). |
| **`fastcgi_buffering off`** | Reduces latency for long-running demo requests (e.g. `simulate_ms` in capacity tests). |

**Which tests prove it**

- `req5-load-before.js` — hits `/api/before/products` (single-app behavior baseline).
- `req5-load-after.js` — hits `/api/after/products` through Nginx; both `app` and `app2` serve traffic.

**Without this file:** only one PHP container would receive traffic; Requirement 5 could not be demonstrated as load distribution across multiple app instances.

---

### `docker/php-fpm/zzz-custom.conf` — Requirement 2 (Capacity Control)

PHP-FPM’s default pool is small (often ~5 workers). That creates a **hidden queue** in front of Laravel:

1. k6 sends 80 concurrent requests (`req2-capacity-after.js`).
2. Only ~5 enter PHP at a time; the rest wait in FPM/nginx.
3. `CapacityLimiterMiddleware` never sees the full burst → requests **time out** instead of receiving intentional **`503`** responses.

This pool config raises concurrency so requests reach Laravel quickly and the **application** capacity guard can work:

```ini
pm = dynamic
pm.max_children = 50
pm.start_servers = 10
pm.min_spare_servers = 5
pm.max_spare_servers = 20
pm.max_requests = 500
```

| Setting | Role in the project |
|--------|---------------------|
| **`pm.max_children = 50`** | Enough workers per container so a burst can enter PHP while others hold slots with `simulate_ms=800`. |
| **`pm.start_servers` / spare settings** | Warm pool ready when k6 starts; avoids cold-start queueing that would skew capacity metrics. |
| **`pm.max_requests = 500`** | Recycles workers during long test sessions to limit memory drift. |

**Which tests prove it**

- **`req2-capacity-after.js`** (primary) — expects `capacity_rejected_rate > 0` and fast **`503`** from `CapacityLimiterMiddleware`, not 15s timeouts.
- **`req5-load-after.js`**, **`req9-stress-after.js`** — benefit from a larger pool so failures reflect app behavior, not FPM starvation.

**How it is loaded**

- Baked into the image via `Dockerfile` (`COPY docker/php-fpm/zzz-custom.conf …`).
- Mounted at runtime in `docker-compose.yml` so pool changes apply without rebuild.

**Without this file:** Requirement 2’s after test would not reliably show Redis-backed capacity rejection; the bottleneck would be PHP-FPM, not `CapacityLimiterMiddleware`.

---

## 1. Save k6 Results to Files

Run all before/after k6 tests and save JSON summaries:

```powershell
.\scripts\run-k6-requirements.ps1
```

The result files will be saved under:

```text
storage/k6/<timestamp>/
```

You can open each JSON file and compare metrics like:

-   `metrics.http_req_duration.values.avg`
-   `metrics.http_req_duration.values.p(95)`
-   `metrics.http_req_failed.values.rate`
-   `metrics.checks.values.rate`

Extra custom k6 metrics added for the presentation:

-   Requirement 1 before: `orders_accepted_without_stock_guard` and `accepted_rate`
-   Requirement 1 after: `orders_accepted_with_stock_guard`, `orders_safely_rejected`, and `safe_rejected_rate`
-   Requirement 2 after: `capacity_rejected_rate`
-   Requirement 3 after: `queued_order_response_rate`
-   Requirement 4 after: `report_queued_rate`

How to observe the before/after difference:

1. Compare p95 duration: `metrics.http_req_duration.values["p(95)"]`.
2. Compare success checks: `metrics.checks.values.rate`.
3. For race-condition safety, before should show many accepted orders even though the test product starts with stock `5`; after should show successful orders plus safe `409 Conflict` rejections.
4. For capacity control, after should intentionally show some `503` responses and `capacity_rejected_rate > 0`, proving the service rejects excess concurrent work instead of letting all requests enter the application.
5. For queues and batch work, after should return quickly with `201` order responses or `202` report responses while the `worker` service processes jobs in the background.

## Requirement 1: Concurrent Access & Data Integrity

**Old problem**

Solved comparison endpoint:

```text
POST /api/before/orders
```

Old function:

```text
App\Services\EcommerceNfrService::createLegacyOrder
```

The old code updates product stock without Redis lock and without row-level database locking. Under concurrent order creation, it can create conflicting stock updates.

Run old k6 test:

```bash
docker compose --profile test run --rm k6 run /scripts/req1-race-before.js --summary-export /results/req1-race-before.json
```

**New solution**

Solved by:

```text
App\Services\EcommerceNfrService::createOptimizedOrder
```

The new code uses:

-   Redis lock: `ecommerce:order:create:{productIds}`
-   `DB::transaction`
-   `lockForUpdate`
-   stock validation before decrement
-   safe `409 Conflict` when stock is not enough

Run new k6 test:

```bash
docker compose --profile test run --rm k6 run /scripts/req1-race-after.js --summary-export /results/req1-race-after.json
```

## Requirement 2: Resource Management & Capacity Control

**Old problem**

Endpoint:

```text
GET /api/before/products
```

The old path has no capacity guard. Many parallel requests can all enter expensive work at the same time.

Run old k6 test:

```bash
docker compose --profile test run --rm k6 run /scripts/req2-capacity-before.js --summary-export /results/req2-capacity-before.json
```

**New solution**

Solved by:

```text
App\Http\Middleware\CapacityLimiterMiddleware
```

Attached in:

```text
routes/api.php
```

The new path limits active concurrent API work. If capacity is full, it returns `503` intentionally instead of letting the server collapse.

**Infrastructure note:** see [§0.1 PHP-FPM pool](#01-infrastructure-nginx--php-fpm) — the FPM worker limit must be high enough that this middleware receives the burst; otherwise requests queue below Laravel and the k6 test times out instead of recording `503`.

Run new k6 test:

```bash
docker compose --profile test run --rm k6 run /scripts/req2-capacity-after.js --summary-export /results/req2-capacity-after.json
```

## Requirement 3: Asynchronous Queues

**Old problem**

Endpoint:

```text
POST /api/before/orders
```

Old function:

```text
App\Services\EcommerceNfrService::createLegacyOrder
```

The old flow simulates doing non-critical receipt work inside the request path, so the user waits longer.

Run old k6 test:

```bash
docker compose --profile test run --rm k6 run /scripts/req3-queue-before.js --summary-export /results/req3-queue-before.json
```

**New solution**

Solved by:

```text
App\Jobs\SendOrderReceiptJob
```

Dispatched from:

```text
App\Services\EcommerceNfrService::createOptimizedOrder
```

The request returns after creating the order. Receipt/log notification work is moved to Redis queue and processed by Docker service:

```text
worker
```

Run new k6 test:

```bash
docker compose --profile test run --rm k6 run /scripts/req3-queue-after.js --summary-export /results/req3-queue-after.json
```

Show worker evidence:

```bash
docker compose logs --tail=60 worker
```

## Requirement 4: Batch Processing

**Old problem**

Endpoint:

```text
GET /api/before/reports/daily-sales
```

Old controller:

```text
App\Http\Controllers\BeforeReportController::dailySales
```

The old flow loads all daily orders into memory and calculates the report inside the HTTP request.

Run old k6 test:

```bash
docker compose --profile test run --rm k6 run /scripts/req4-batch-before.js --summary-export /results/req4-batch-before.json
```

**New solution**

Endpoint:

```text
POST /api/after/reports/daily-sales
```

Solved by:

```text
App\Jobs\BuildDailySalesSummaryJob
```

The job processes orders using:

```php
chunkById(100, ...)
```

The API queues the background job and returns quickly with `202 Accepted`. The worker processes the data and stores it in:

```text
daily_sales_summaries
```

Run new k6 test:

```bash
docker compose --profile test run --rm k6 run /scripts/req4-batch-after.js --summary-export /results/req4-batch-after.json
```

You can also run the batch synchronously for proof:

```bash
docker compose exec app php artisan sales:summarize-daily --sync
```

## Requirement 5: Load Distribution

**Old problem**

Single backend instance only:

```text
app:8000
```

Run old k6 test:

```bash
docker compose --profile test run --rm k6 run /scripts/req5-load-before.js --summary-export /results/req5-load-before.json
```

**New solution**

Solved by:

```text
docker/nginx.conf
```

Docker runs two app servers:

```text
app
app2
```

And Nginx distributes requests using:

```nginx
least_conn;
```

Why `least_conn`: e-commerce requests do not all take the same time, so sending a new request to the server with fewer active connections is better than simple round-robin.

**Infrastructure note:** see [§0.1 Nginx upstream](#01-infrastructure-nginx--php-fpm) for how `upstream`, `least_conn`, and `fastcgi_pass` wire both containers into one load-balanced entry point.

Run new k6 test:

```bash
docker compose --profile test run --rm k6 run /scripts/req5-load-after.js --summary-export /results/req5-load-after.json
```

## Normal API Examples

List products before optimization:

```bash
curl -i http://localhost:8000/api/before/products?limit=20
```

List products after optimization twice:

```bash
curl -i http://localhost:8000/api/after/products?limit=20
curl -i http://localhost:8000/api/after/products?limit=20
```

The second response should show:

```text
X-Backend-Cache: hit
```

Create order after optimization:

```bash
curl -X POST http://localhost:8000/api/after/orders ^
  -H "Content-Type: application/json" ^
  -d "{\"customer_email\":\"buyer@example.com\",\"items\":[{\"product_id\":1,\"quantity\":1}]}"
```

## Requirement 6: Distributed Caching (Redis)

**Old problem**

Endpoint:

```text
GET /api/before/hot-products
```

Function:

```text
App\Services\EcommerceNfrService::legacyHotProducts
```

Every request scans the database and recalculates a legacy score. No Redis layer.

Run old k6 test:

```bash
docker compose --profile test run --rm k6 run /scripts/req6-cache-before.js --summary-export /results/req6-cache-before.json
```

**New solution**

Endpoint:

```text
GET /api/after/hot-products
```

Function:

```text
App\Services\EcommerceNfrService::optimizedHotProducts
```

Hot products are stored in Redis (`ecommerce:hot-products:v1:limit:{n}`) for 60 seconds. Response headers expose cache state:

```text
X-Backend-Cache: miss | hit
```

Run new k6 test:

```bash
docker compose --profile test run --rm k6 run /scripts/req6-cache-after.js --summary-export /results/req6-cache-after.json
```

Compare:

- `cache_hit_rate` (after should be > 0, before must stay 0)
- `hot_products_read_ms` p95 (after should be lower after warmup)

## Requirement 7: Concurrency Control (Optimistic Locking)

**Old problem**

Endpoint:

```text
POST /api/before/products/{id}/stock-adjust
```

Function:

```text
App\Services\EcommerceNfrService::legacyStockAdjustment
```

Read-modify-write without `stock_version` check.

Run old k6 test:

```bash
docker compose --profile test run --rm k6 run /scripts/req7-lock-before.js --summary-export /results/req7-lock-before.json
```

**New solution**

Endpoint:

```text
POST /api/after/products/{id}/stock-adjust
```

Function:

```text
App\Services\EcommerceNfrService::optimizedStockAdjustment
```

Uses optimistic locking:

```sql
UPDATE products
SET stock = stock + :delta, stock_version = stock_version + 1
WHERE id = :id AND stock_version = :expected
```

Stale versions return `409 Conflict`.

Run new k6 test:

```bash
docker compose --profile test run --rm k6 run /scripts/req7-lock-after.js --summary-export /results/req7-lock-after.json
```

## Requirement 8: Transaction Integrity (ACID)

**Old problem**

Endpoint:

```text
POST /api/before/checkout
```

Function:

```text
App\Services\EcommerceNfrService::legacyCheckoutWithPayment
```

Payment failure happens after order/stock writes, leaving partial state (`partial_state_possible: true`).

Run old k6 test:

```bash
docker compose --profile test run --rm k6 run /scripts/req8-acid-before.js --summary-export /results/req8-acid-before.json
```

**New solution**

Endpoint:

```text
POST /api/after/checkout
```

Function:

```text
App\Services\EcommerceNfrService::optimizedCheckoutWithPayment
```

Payment, inventory, and order creation run inside one `DB::transaction`. Failure rolls back everything (`rolled_back: true`).

Run new k6 test:

```bash
docker compose --profile test run --rm k6 run /scripts/req8-acid-after.js --summary-export /results/req8-acid-after.json
```

## Requirement 9: Stress Testing (100+ concurrent users)

**Old problem**

Endpoint under stress:

```text
GET /api/before/hot-products
```

100 virtual users for 30 seconds. The system may slow down but should stay reachable.

Run old k6 test:

```bash
docker compose --profile test run --rm k6 run /scripts/req9-stress-before.js --summary-export /results/req9-stress-before.json
```

**New solution**

Endpoint under stress:

```text
GET /api/after/hot-products
```

Same 100 users, but Redis cache + capacity middleware keep failure rate low and health checks green after the run.

Run new k6 test:

```bash
docker compose --profile test run --rm k6 run /scripts/req9-stress-after.js --summary-export /results/req9-stress-after.json
```

## Requirement 10: Benchmarking & Bottleneck Analysis

**Old problem**

Endpoint:

```text
GET /api/before/benchmarks/products
```

Bottleneck header:

```text
X-Benchmark-Bottleneck: direct-database-scan
```

Run old k6 test:

```bash
docker compose --profile test run --rm k6 run /scripts/req10-bench-before.js --summary-export /results/req10-bench-before.json
```

**New solution**

Endpoint:

```text
GET /api/after/benchmarks/products
```

Bottleneck header:

```text
X-Benchmark-Bottleneck: redis-cache
```

Compare JSON field `duration_ms` and k6 trend `benchmark_duration_ms` before vs after.

Run new k6 test:

```bash
docker compose --profile test run --rm k6 run /scripts/req10-bench-after.js --summary-export /results/req10-bench-after.json
```

## GUI: Grafana + InfluxDB (Docker Desktop)

Start observability stack:

```bash
docker compose --profile observability up -d influxdb grafana
```

Open Grafana:

```text
http://localhost:3000
```

Login: `admin` / `admin` (first login may ask to change password).

Run one k6 test with live metrics (example: requirement 6 after):

```powershell
.\scripts\run-k6-grafana.ps1 -Test req6-cache-after
```

Or run all tests to JSON files:

```powershell
.\scripts\run-k6-requirements.ps1
```

Run a single requirement:

```powershell
.\scripts\run-k6-single.ps1 -Test req6-cache-after
```

Telescope (request-level GUI inside Laravel):

```bash
# enable in docker-compose app environment: TELESCOPE_ENABLED=true
docker compose exec app php artisan telescope:install
docker compose exec app php artisan migrate
```

Then open:

```text
http://localhost:8000/telescope
```

## Laravel Tests

Local tests:

```bash
php artisan test
```

Or inside Docker:

```bash
docker compose exec app php artisan test
```

Expected: all `EcommerceNfrTest` cases pass, including requirements 6, 7, 8, and 10.
