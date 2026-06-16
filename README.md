# Delupe — Product Feed Service

A containerized PHP / **Symfony 7.1** service that imports merchant product feeds
from JSON, validates and stores them in **PostgreSQL**, performs bulk price
adjustments, and exposes the data through a secured **REST API**.

---

## Table of contents

1. [Project overview](#project-overview)
2. [Tech stack](#tech-stack)
3. [Quick start (Docker)](#quick-start-docker)
4. [Configuration](#configuration)
5. [Database & migrations](#database--migrations)
6. [CLI commands](#cli-commands)
   - [Importing products](#importing-products)
   - [Asynchronous (queued) import](#asynchronous-queued-import)
   - [Price adjustment](#price-adjustment)
7. [REST API](#rest-api)
8. [Running the tests](#running-the-tests)
9. [Static analysis & coding standards](#static-analysis--coding-standards)
10. [Continuous integration](#continuous-integration)
11. [Project structure](#project-structure)
12. [Design notes](#design-notes)

---

## Project overview

Delupe receives product feeds from merchants. This service:

- **Imports** product data from JSON files via a CLI command.
- **Validates** each record (name, price, ISO currency) and logs invalid ones.
- **Stores** products in PostgreSQL, **inserting new** records and **updating existing**
  ones (matched on `merchant_id` + product id).
- **Adjusts prices** in bulk by a percentage while **preserving the original price**.
- **Exposes** products through a REST API with pagination, filtering, a summary
  report and a duplicate-detection endpoint.
- Runs fully **Dockerized** and starts with a single command.

Bonus features included: **health-check endpoint**, **queue-based async import**
(Symfony Messenger on a PostgreSQL-backed transport), and **GitHub Actions CI**
(tests + PHPStan + coding standards).

---

## Tech stack

| Concern            | Choice                                            |
|--------------------|---------------------------------------------------|
| Language / runtime | PHP 8.3                                            |
| Framework          | Symfony 7.1                                        |
| Database           | PostgreSQL 16                                      |
| ORM / migrations   | Doctrine ORM 3 + Doctrine Migrations              |
| Queue              | Symfony Messenger (Doctrine/PostgreSQL transport) |
| Web server         | Nginx + PHP-FPM                                    |
| Tests              | PHPUnit 11                                         |
| Static analysis    | PHPStan (level 6)                                  |
| Coding standards   | PHP-CS-Fixer (`@Symfony`)                          |
| Containerization   | Docker + Docker Compose                           |

---

## Quick start (Docker)

> Requires Docker and Docker Compose. No local PHP needed.

```bash
docker compose up -d
```

Docker Compose will pull any missing base images and build the custom ones
automatically on the first run — no extra flags needed.

If you later change the `Dockerfile` and need to force a rebuild of already-cached
images, add `--build`:

```bash
docker compose up -d --build
```

> If host port `8080` is already in use, pick another:
> `HTTP_PORT=8088 docker compose up -d` (then use `http://localhost:8088`).

This starts four services:

| Service  | Description                                       | Port |
|----------|---------------------------------------------------|------|
| `db`     | PostgreSQL 16                                     | 5432 |
| `php`    | PHP-FPM app container (runs migrations on boot)   | —    |
| `nginx`  | Web server serving the API                        | 8080 |
| `worker` | Messenger worker that processes queued imports    | —    |

On first boot the `php` container waits for the database, installs Composer
dependencies (if needed) and **runs migrations automatically**.

Verify it is up:

```bash
curl http://localhost:8080/health
# {"status":"ok","database":"connected"}
```

> **Catalog is empty on first boot.** The database starts with no products.
> [→ Import your first products](#importing-products) via the CLI, or use the
> one-click **Tools** view in the web dashboard — no terminal needed.

Then open the **web dashboard** in a browser:

```
http://localhost:8080/
```

A `Makefile` wraps the common commands — run `make help` to list them.

### Web dashboard (Vue 3 SPA)

A modern single-page app (**Vue 3**, dark theme) is served at `/`. It has four
views — Dashboard (summary cards + currency donut chart), Products (paginated,
filterable table), Duplicates, and Tools (import a feed + adjust prices).

**Login required.** The dashboard is protected by a username/password form login
(Symfony Security, session-based, hashed passwords in a `users` table). An admin
user is seeded automatically on first boot from `ADMIN_USERNAME` / `ADMIN_PASSWORD`
(default **`admin` / `admin`** — change before real use). Sign in at `/login`.

Two independent auth layers:

- **Dashboard (web)** → session login. Protects `/`, `/app-api/*`, `/docs`.
- **REST API (programmatic)** → `X-API-Key` header. Protects `/api/*`.

`/health` and `/openapi.yaml` are public.

Architecture — the API key never reaches the browser:

- The SPA (`public/js/app.js`, Vue loaded via an ES-module import map; no Node
  build step) talks to a same-origin **BFF** under `/app-api/*`
  (`src/Controller/BffController.php`), which calls the services/repository
  directly on the server.
- The public, key-protected REST API for external consumers stays at `/api/*`
  (still requires the `X-API-Key` header) and is fully independent of the SPA.

| BFF endpoint (used by the SPA)        | Purpose                              |
|---------------------------------------|--------------------------------------|
| `GET /app-api/summary`                | Dashboard stats                      |
| `GET /app-api/products`               | Filtered, paginated list             |
| `GET /app-api/duplicates`             | Name/link collisions                 |
| `POST /app-api/import`                | Import (upload / sample, sync/async) |
| `POST /app-api/adjust-prices`         | Bulk percentage price change         |

> The `/app-api/*` BFF is unauthenticated by design (it is the local dashboard's
> backend). In production it would sit behind user authentication. External,
> programmatic access goes through the key-protected `/api/*` API.
>
> Note: the SPA loads Vue and Chart.js from public CDNs, so the dashboard needs
> internet access in the browser. The JSON API and CLI work fully offline.

---

## Configuration

Configuration is environment-variable driven (see `.env` for defaults):

| Variable                  | Default                                   | Purpose                                   |
|---------------------------|-------------------------------------------|-------------------------------------------|
| `APP_ENV`                 | `dev`                                     | Symfony environment                       |
| `DATABASE_URL`            | `postgresql://app:app@db:5432/app`        | PostgreSQL DSN                            |
| `MESSENGER_TRANSPORT_DSN` | `doctrine://default?queue_name=import`    | Async transport (PostgreSQL-backed)       |
| `API_KEY`                 | `change_me_super_secret_key`              | Key required in the `X-API-Key` header    |
| `ADMIN_USERNAME`          | `admin`                                   | Dashboard login username (seeded on boot) |
| `ADMIN_PASSWORD`          | `admin`                                   | Dashboard login password (seeded on boot) |
| `APP_SECRET`              | _(set in `.env`)_                         | Symfony secret                            |

Override any of these via your shell, an `.env.local` file, or the
`environment:` block in `docker-compose.yml`. **Change `API_KEY` before any real use.**

---

## Database & migrations

The schema is created exclusively through migrations (no manual SQL).

`products` table:

| Field            | Type                         | Notes                                  |
|------------------|------------------------------|----------------------------------------|
| `id`             | int (identity)               | Primary key (surrogate)                |
| `external_id`    | varchar(191)                 | Product id from the feed               |
| `merchant_id`    | varchar(191)                 | Merchant identifier                    |
| `name`           | varchar(500)                 | Product name                           |
| `link`           | varchar(1000)                | Product URL                            |
| `image_link`     | varchar(1000), nullable      | Image URL                              |
| `price`          | numeric(12,2)                | Current price                          |
| `original_price` | numeric(12,2), nullable      | Price before adjustment                |
| `currency`       | varchar(3)                   | ISO 4217 code                          |
| `created_at`     | timestamp                    |                                        |
| `updated_at`     | timestamp                    |                                        |

A unique constraint on `(merchant_id, external_id)` is the natural key used for
upserts.

Migrations run automatically on container boot. To run them manually:

```bash
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
# or
make migrate
```

---

## CLI commands

### Importing products

#### Quick import via the web dashboard (no CLI needed)

1. Open `http://localhost:8080/` and sign in (`admin` / `admin`).
2. Click **Tools** in the left sidebar.
3. Leave the file picker empty to use the bundled `products.json` sample, or
   click **Choose file** to upload your own JSON feed.
4. Click **Run import** — imported / updated / failed counts appear immediately
   below the button.

> To process large feeds in the background instead, tick **Process asynchronously**
> before clicking **Run import**. The `worker` container will pick it up
> automatically — no extra steps needed.

#### Via the CLI

```bash
docker compose exec php php bin/console app:import-products products.json
# or
make import
```

The command:

- reads products from the JSON file,
- validates every record,
- inserts new products and updates existing ones (by `merchant_id` + product id),
- logs invalid records,
- prints a summary of **imported / updated / failed** counts plus a table of invalid rows.

**Validation rules:** product name must not be empty, price must be greater than
zero, currency must be a valid ISO 4217 code (e.g. `EUR`, `USD`, `GBP`).

The bundled `products.json` intentionally contains two invalid records (empty
name + zero price, and an invalid currency) and some duplicates so you can see
every feature in action.

### Asynchronous (queued) import

Instead of processing inline, queue the job and let the `worker` service handle it:

```bash
docker compose exec php php bin/console app:import-products products.json --async
# or
make import-async
```

The job is dispatched onto a PostgreSQL-backed Messenger transport. The `worker`
container consumes it automatically. To run a worker yourself:

```bash
docker compose exec php php bin/console messenger:consume async failed -vv
```

### Price adjustment

```bash
docker compose exec php php bin/console app:update-prices 10     # +10%
docker compose exec php php bin/console app:update-prices -5     # -5%
# or
make update-prices P=10
```

- accepts a percentage (positive or negative),
- before adjusting, stores the pre-adjustment price into `original_price`
  **only when it is not already set**, so the true original is never lost on
  repeated runs,
- applies the percentage to every product price,
- prints the number of affected products.

---

## REST API

All `/api/*` endpoints require an API key header:

```
X-API-Key: change_me_super_secret_key
```

Base URL (Docker): `http://localhost:8080`

### Interactive docs (Swagger UI)

Open **`http://localhost:8080/docs`** for live, interactive API documentation
(Swagger UI). Click **Authorize**, paste the API key, and use **Try it out** to
call the endpoints from the browser. The OpenAPI 3.0 spec is served at
`/openapi.yaml` (both paths are public; the documented `/api/*` endpoints still
require the key).

### `GET /api/products`

Paginated, filterable listing.

| Query param | Default | Description                       |
|-------------|---------|-----------------------------------|
| `page`      | `1`     | Page number                       |
| `limit`     | `50`    | Page size (max 100)               |
| `currency`  | —       | Filter by ISO currency code       |
| `min_price` | —       | Minimum price (inclusive)         |
| `max_price` | —       | Maximum price (inclusive)         |

```bash
curl -H "X-API-Key: change_me_super_secret_key" \
  "http://localhost:8080/api/products?page=1&limit=50&currency=EUR&min_price=100&max_price=500"
```

```json
{
  "data": [
    {
      "id": 1,
      "external_id": "SKU-1001",
      "merchant_id": "merchant-001",
      "name": "Wireless Headphones",
      "link": "https://shop.example.com/p/1001",
      "image_link": "https://cdn.example.com/img/1001.jpg",
      "price": 199.99,
      "original_price": 249.99,
      "currency": "EUR",
      "created_at": "2026-06-15T10:00:00+00:00",
      "updated_at": "2026-06-15T10:00:00+00:00"
    }
  ],
  "pagination": { "page": 1, "limit": 50, "total": 1, "pages": 1 },
  "filters": { "currency": "EUR", "min_price": 100, "max_price": 500 }
}
```

### `GET /api/products/summary`

```bash
curl -H "X-API-Key: change_me_super_secret_key" \
  "http://localhost:8080/api/products/summary"
```

```json
{
  "count": 1000,
  "total_price": 45670.00,
  "average_price": 45.67,
  "currencies": { "EUR": 500, "USD": 500 }
}
```

### `GET /api/products/duplicates`

Returns products that share the **same name** OR the **same link**.

```bash
curl -H "X-API-Key: change_me_super_secret_key" \
  "http://localhost:8080/api/products/duplicates"
```

```json
{
  "count": 4,
  "data": [ /* products that collide on name or link */ ]
}
```

### `GET /health` (public — no API key)

```bash
curl http://localhost:8080/health
```

```json
{ "status": "ok", "database": "connected" }
```

---

## Running the tests

Tests cover **import functionality**, **validation logic**, and the **summary
endpoint** (plus API-key security, filtering, pagination and health check).

The functional/integration tests need a PostgreSQL test database. Using the
running stack:

```bash
make test
```

Or manually inside the container:

```bash
docker compose exec php sh -lc '
  export APP_ENV=test \
         API_KEY=test_api_key \
         DATABASE_URL="postgresql://app:app@db:5432/app_test?serverVersion=16&charset=utf8";
  php bin/console doctrine:database:create --if-not-exists --env=test;
  vendor/bin/phpunit'
```

> `API_KEY=test_api_key` is set explicitly because the `docker-compose.yml`
> environment value would otherwise override the one in `.env.test` and break
> the API-key functional test.

The pure validation tests (`tests/Validation`) run without any database.

---

## Static analysis & coding standards

```bash
# PHPStan (level 6)
docker compose exec php sh -lc "php bin/console cache:warmup --env=dev && vendor/bin/phpstan analyse"

# PHP-CS-Fixer (dry run)
docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff

# Auto-fix
docker compose exec php vendor/bin/php-cs-fixer fix
```

---

## Continuous integration

`.github/workflows/ci.yml` runs on every push and pull request:

- **Tests** — PHPUnit against a PostgreSQL service container.
- **Static analysis** — PHPStan level 6.
- **Coding standards** — PHP-CS-Fixer (`--dry-run`).

---

## Project structure

```
.
├── bin/console                     # Symfony console entrypoint
├── config/                         # Framework, Doctrine, Messenger, Monolog config
├── docker/                         # Nginx + PHP-FPM config and entrypoint
├── migrations/                     # Doctrine migrations (products table)
├── public/
│   ├── index.php                   # Web entrypoint
│   ├── openapi.yaml                # OpenAPI 3.0 spec (Swagger UI source)
│   ├── css/app.css                 # SPA styles (modern dark theme)
│   └── js/app.js                   # Vue 3 SPA (import map, no build step)
├── templates/                      # Twig shells
│   ├── app.html.twig               # Vue SPA shell (served at /)
│   ├── docs.html.twig              # Swagger UI page (served at /docs)
│   └── security/login.html.twig    # Login form
├── src/
│   ├── Command/                    # app:import-products, app:update-prices, app:ensure-admin
│   ├── Controller/                 # Shell (SPA) + Bff + Product API + Docs + Security (login) + health
│   ├── Dto/ProductInput.php        # Validated import record + feed-key aliasing
│   ├── Entity/                     # Product, User
│   ├── EventSubscriber/            # API-key security (for /api/*)
│   ├── Message/ + MessageHandler/  # Async import (Symfony Messenger)
│   ├── Repository/                 # ProductRepository, UserRepository
│   └── Service/                    # FeedReader, ProductImporter, PriceAdjuster, ImportResult
├── tests/                          # Validation, importer, API tests
├── docker-compose.yml
├── Dockerfile
├── Makefile
└── README.md
```

---

## Design notes

- **Upsert key.** The feed's product id is stored as `external_id`; uniqueness is
  enforced on `(merchant_id, external_id)`. This lets the same product id appear
  for different merchants while still allowing "update if it already exists".
- **Lenient feed parsing.** `ProductInput::fromArray()` accepts both the canonical
  keys and common Google-style feed aliases (`title`, `url`, `sale_price`,
  `currency_code`, …) and parses prices like `"199.99 EUR"`.
- **Validation** is declarative via Symfony Validator constraints on the DTO; the
  importer collects violations per record and never aborts the whole batch.
- **Bulk price updates** use DQL `UPDATE` statements inside a transaction for
  efficiency on large catalogs.
- **Security** is a lightweight `X-API-Key` check (constant-time comparison) on
  every `/api/*` request via a kernel event subscriber; `/health` is public.
- **Logging** uses Monolog on a dedicated `import` channel (import started/
  completed, success/failure counts, validation errors), emitted to stderr in
  prod so Docker captures it.
- **Composer security policy.** `config.policy.advisories.block` is set to
  `false` in `composer.json`. Recent Composer versions refuse to install any
  package version flagged by a security advisory; in the build environment used
  here every Symfony 7.x patch was flagged, which made the project impossible to
  install. Disabling the hard block keeps `composer install` deterministic;
  `composer audit` still reports advisories. Pin to specific patched versions if
  you re-enable it.
