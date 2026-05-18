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

## 1. Save k6 Results to Files

Run all before/after k6 tests and save JSON summaries:

```powershell
.\scripts\run-k6-requirements.ps1
```

The result files will be saved under:

```text
storage/k6/results/<timestamp>/
```

You can open each JSON file and compare metrics like:

- `metrics.http_req_duration.values.avg`
- `metrics.http_req_duration.values.p(95)`
- `metrics.http_req_failed.values.rate`
- `metrics.checks.values.rate`

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

- Redis lock: `ecommerce:order:create:{productIds}`
- `DB::transaction`
- `lockForUpdate`
- stock validation before decrement
- safe `409 Conflict` when stock is not enough

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
App\Http\Middleware\CapacityLimiter
```

Attached in:

```text
routes/api.php
```

The new path limits active concurrent API work. If capacity is full, it returns `503` intentionally instead of letting the server collapse.

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

## Laravel Tests

Local tests:

```bash
php artisan test
```

Expected:

```text
9 passed
```
