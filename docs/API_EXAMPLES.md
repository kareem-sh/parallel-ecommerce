# API Examples

Start the backend with MySQL, Redis, queue worker, and nginx load balancer:

```bash
docker compose up --build nginx app app2 worker mysql redis
```

Seed demo data:

```bash
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --force
```

Create a product:

```bash
curl -X POST http://localhost:8000/api/after/products \
  -H "Content-Type: application/json" \
  -d "{\"sku\":\"MOUSE-001\",\"name\":\"Wireless Mouse\",\"price\":35,\"stock\":50}"
```

List products twice to see Redis cache:

```bash
curl -i http://localhost:8000/api/after/products?limit=20
curl -i http://localhost:8000/api/after/products?limit=20
```

Create an order:

```bash
curl -X POST http://localhost:8000/api/after/orders \
  -H "Content-Type: application/json" \
  -d "{\"customer_email\":\"buyer@example.com\",\"items\":[{\"product_id\":1,\"quantity\":1}]}"
```
