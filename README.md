# orderProcess

Order-processing application, built as a **modular monolith** on Symfony 8.1 / PHP 8.4, with PostgreSQL (Doctrine ORM) and RabbitMQ (Symfony Messenger). Business context: orders, product stock, payments.

The project is structured after DDD / hexagonal architecture principles: the domain is framework-agnostic (no ORM, Messenger, HTTP, or AMQP — only portable libraries, like `ramsey/uuid` and `doctrine/collections`), and the infrastructure connects through ports (interfaces).

---

## Table of contents

- [Stack](#stack)
- [Architecture](#architecture)
- [The "place order" flow](#the-place-order-flow)
- [Domain model](#domain-model)
- [Persistence](#persistence)
- [Messaging and outbox](#messaging-and-outbox)
- [HTTP API](#http-api)
- [Web interface](#web-interface)
- [Setup and startup](#setup-and-startup)
- [Available commands](#available-commands)
- [Manually testing the flow](#manually-testing-the-flow)
- [Testing and quality gates](#testing-and-quality-gates)
- [Current status](#current-status)

---

## Stack

| Component | Version / image |
|---|---|
| PHP | 8.4 (`php:8.4-cli-alpine`) |
| Symfony | 8.1 (framework-bundle, console, messenger, validator) |
| Doctrine ORM | ^3.7 + doctrine-migrations-bundle ^4 |
| PostgreSQL | 16 (`postgres:16-alpine`) |
| RabbitMQ | 4 (`rabbitmq:4-management-alpine`) |
| Testing | PHPUnit ^13 |
| Static analysis | PHPStan ^2 (level 6) + phpstan-symfony |
| Style | PHP-CS-Fixer ^3 (PSR-12) |

## Architecture

One directory per bounded context under `src/` — currently only `Orders/` (namespace `App\Orders\`):

```
src/Orders/
  Domain/
    Model/            # Entities + value objects. Framework-agnostic (see agents.md).
    Event/            # Domain events, past tense (OrderPlaced, OrderPaid, ...)
    Port/             # Interfaces: OrderRepository, ProductRepository, PaymentGateway
  Application/
    Command/          # Commands (VerbNoun) + handlers (use-case orchestration)
    Port/             # Application ports: DomainEventDispatcher, TransactionManager
  Infrastructure/
    Persistence/      # Doctrine implementations: repositories, Mapping/ (XML), Type/ (DBAL types)
    Messaging/        # MockPaymentGateway, OutboxDomainEventDispatcher
  UI/
    Command/          # bin/console commands
    Controller/       # HTTP controllers (still empty)
```

**Dependencies flow inwards.** `Domain/` never imports from `Application/`, `Infrastructure/`, or `UI/`. Concrete implementations are wired to interfaces in [config/services.yaml](config/services.yaml):

```yaml
App\Orders\Domain\Port\OrderRepository:            '@...\DoctrineOrderRepository'
App\Orders\Domain\Port\ProductRepository:          '@...\DoctrineProductRepository'
App\Orders\Domain\Port\PaymentGateway:             '@...\MockPaymentGateway'
App\Orders\Application\Port\DomainEventDispatcher: '@...\OutboxDomainEventDispatcher'
App\Orders\Application\Port\TransactionManager:    '@...\DoctrineTransactionManager'
```

Detailed design rules (naming conventions, what's forbidden, how to add new code) live in [agents.md](agents.md).

## The "place order" flow

The entry point is the CLI command `app:place-order` → [PlaceOrderCommand.php](src/Orders/UI/Command/PlaceOrderCommand.php), which builds a `PlaceOrder` and sends it to [PlaceOrderHandler.php](src/Orders/Application/Command/PlaceOrderHandler.php).

The entire handler runs **inside a single database transaction** (`TransactionManager::transactional()`):

1. **Check stock + decrement** — for each line, the product is loaded from the repository; `Product::decrementStock()` throws `DomainException` if stock is insufficient, and records `ProductOutOfStock` / `StockBelowThreshold` when applicable.
2. **Build the order** — `Order::place()` creates the order in the `PLACED` state and records `OrderPlaced`.
3. **Payment** — `PaymentGateway::charge()` (currently a mock, accepts any amount). Failure throws `DomainException` and rolls back the whole transaction.
4. **Save** — the order and the modified products are persisted.
5. **Dispatch events** — the events released via `releaseEvents()` are written to the `outbox` table, in the **same** transaction.

This way, the stock, the order, and the events either commit together or not at all — there's no window where the order exists but the event was lost (or vice versa).

## Domain model

**Entities**

- `Order` — identity + behavior: `place()`, `markPaid()`, `cancel()`, `total()`. The constructor is private; orders are only created through the `place()` factory. Rejects two lines for the same product, rejects paying an order that isn't `PLACED`, and rejects cancelling one that's already paid.
- `OrderItem` — an order line: product, quantity, unit price, `subtotal()`.
- `Product` — name, price, `stockQuantity`, `minThreshold`, plus `version` for **optimistic locking** (Doctrine). `decrementStock()` / `restock()` / `isBelowThreshold()`.

**Value objects** (immutable, validated in the constructor)

| VO | Rules |
|---|---|
| `Money` | amount in cents (int), default currency `RON`; rejects negative values and operations across different currencies |
| `Quantity` | strictly positive integer |
| `OrderId`, `CustomerId`, `ProductId` | UUID identifiers |
| `OrderStatus` | enum: `placed`, `paid`, `cancelled` |

**Domain events** — `OrderPlaced`, `OrderPaid`, `OrderCancelled`, `ProductOutOfStock`, `StockBelowThreshold`, all implementing `DomainEvent` (`occurredAt()`). Accumulated on the entity and released via `releaseEvents()`.

## Persistence

Doctrine mapping is done via **XML**, not attributes — so domain entities stay clean: [src/Orders/Infrastructure/Persistence/Mapping/](src/Orders/Infrastructure/Persistence/Mapping/). Value objects are persisted through custom DBAL types (`order_id`, `customer_id`, `product_id`, `quantity`, `money`) registered in [config/packages/doctrine.yaml](config/packages/doctrine.yaml).

Schema (see [migrations/](migrations/)):

| Table | Content |
|---|---|
| `products` | `id`, `name`, `stock_quantity`, `min_threshold`, `price_amount`, `version` |
| `orders` | `id`, `customer_id`, `status`, `placed_at`, `paid_at` |
| `order_items` | `id`, `order_id` (FK cascade), `product_id`, `quantity_value`, `unit_price_amount` |
| `outbox` | `id`, `type`, `payload` (JSON), `occurred_at`, `status` |

Schema changes are made **exclusively through Doctrine migrations**, never by hand.

## Messaging and outbox

[OutboxDomainEventDispatcher](src/Orders/Infrastructure/Messaging/OutboxDomainEventDispatcher.php) serializes the event and writes a `pending` row to the `outbox` table, using the same DBAL connection as the rest of the transaction. Serialization is done via reflection (event properties are `private readonly`, so `get_object_vars()` would return `{}`), with explicit normalization for `DateTimeImmutable`, `Money`, and objects with `toString()`; an unknown type throws `LogicException` instead of being silently encoded away.

The Messenger configuration ([config/packages/messenger.yaml](config/packages/messenger.yaml)) defines:

- **Buses**: `command.bus` (default), `query.bus`, `event.bus` (`allow_no_handlers`).
- **Transports**: `async` (AMQP, 3× retry, 2× backoff, 10s cap), `failed`, `sync`.
- **Routing**: `App\Orders\Application\Command\*` → `async`, `App\Orders\Application\Query\*` → `sync`.

Baseline rule for any consumer added: **idempotency** — the same message delivered twice must produce a single effect (deduplicate on `message_id`).

### Transaction boundary

`command.bus` has **no** `doctrine_transaction` middleware. Atomicity is declared explicitly in the handler, via the [TransactionManager](src/Orders/Application/Port/TransactionManager.php) port, for two reasons: it's visible in the use-case code, not hidden in configuration, and it doesn't depend on the path the command takes to reach the handler (through the bus or via a direct call from `UI/Command/`).

Consequence for new code: **any handler that performs more than one write opens its own transaction** with `TransactionManager::transactional()`. There is no bus-level safety net.

## HTTP API

A small JSON API in `src/Orders/UI/Controller/`, served by the `php` container on port 8000. Reads go through `query.bus` (sync); the write injects `PlaceOrderHandler` directly, exactly as `UI/Command/PlaceOrderCommand` does — `PlaceOrder` is routed `async`, but the UI needs the resulting status inside the same request, and the handler's own transaction boundary applies either way.

| Endpoint | Description |
|---|---|
| `GET /api/products?limit=50` | catalog entries with stock left (capped at 200) |
| `POST /api/orders` | place an order; returns the final order, already paid |
| `GET /api/orders?page=1&perPage=20` | paginated orders, newest first (`perPage` capped at 100) |
| `GET /api/orders/{id}` | a single order |

All amounts are integers in bani (RON cents), like `Money` on the PHP side.

```bash
curl -X POST localhost:8000/api/orders -H 'Content-Type: application/json' -d '{
  "customerId": "11111111-1111-4111-8111-111111111111",
  "items": [{"productId": "<uuid>", "quantity": 2}]
}'
```

```json
{"id":"...","status":"paid","total":24170,"currency":"RON","items":[...]}
```

Status codes the frontend branches on: `201` placed, `400` malformed payload, `409` the domain refused it (insufficient stock, unknown product, refused payment), `404` unknown order.

The listing is served by a read model (`Application/Port/OrderReadModel`, implemented over raw DBAL in `Infrastructure/Persistence/DoctrineOrderReadModel`) — no ORM hydration, one query for the page plus one for all its lines, and an index matching the `placed_at DESC, id DESC` ordering.

## Web interface

`frontend/` — Vite + React + TypeScript, two pages: a form that places an order and shows its status, and a table of existing orders. Roughly 400 lines, no state library; `src/api.ts` mirrors the API contract as types.

| Route | Page |
|---|---|
| `/` | place an order — pick products, see the status returned by the API |
| `/orders` | existing orders, paginated, newest first |

Routing is `react-router` (the only runtime dependency beyond React), so back/forward work and both pages can be linked to. Leaving a route unmounts its page, which is why the order list refetches on every visit instead of caching. Unknown paths redirect to `/`.

```bash
make up           # the API must be running on :8000
make ui-install   # once
make ui           # dev server on http://localhost:5173
```

The dev server proxies `/api` to `localhost:8000` (see `frontend/vite.config.ts`), so the browser stays on a single origin and the backend needs no CORS configuration. Node runs on the host, not in the `php` container.

`make ui-build` produces a static bundle in `frontend/dist`; `make ui-preview` serves that bundle on **http://localhost:4173** (`vite preview` — build first, this does not watch for changes); `make ui-check` type-checks without emitting.

`:5173` (dev) and `:4173` (preview) each have their own `/api` proxy in `vite.config.ts` — Vite does not share it between the two — and both fall back to `index.html`, so a reload on `/orders` works. Serving `dist/` from any other web server needs that same fallback configured, or deep links return 404.

## Setup and startup

Requirements: Docker + Docker Compose. Everything PHP runs inside the `php` container.

```bash
make build      # build the docker images
make up         # start php (8000), postgres (5432), rabbitmq (5672 + UI 15672)
make install    # composer install in the container
make migrate    # apply Doctrine migrations
```

Exposed services:

| Service | Port | Notes |
|---|---|---|
| php | 8000 | built-in PHP server on `public/` |
| postgres | 5432 | db/user/password: `order` / `order` / `order` |
| rabbitmq | 5672 | management UI on [localhost:15672](http://localhost:15672), `guest` / `guest` |

Configuration comes from `.env` (dev), `.env.dev`, `.env.test` — the important variables are `DATABASE_URL` and `MESSENGER_TRANSPORT_DSN`. In `docker-compose.yml`, the `php` container gets a `DATABASE_URL` that points to the `postgres` hostname (not `127.0.0.1`, as in `.env`). `compose.override.yaml` mounts the sources live into the container, so code changes show up without a rebuild.

**Test data** (dev only):

```bash
make seed-products n=20 seed=123   # populate the catalog; prints the created UUIDs
make seed-orders   n=50 seed=123   # place random orders via app:place-order
```

Both accept an optional `seed` for reproducible runs. `seed-orders` goes through the real flow (stock → payment → outbox), so some orders may legitimately fail on insufficient stock.

## Available commands

All Makefile targets run inside the `php` container; `make help` lists them.

| Command | Effect |
|---|---|
| `make up` / `make down` | start / stop the services |
| `make shell` | shell inside the php container |
| `make install` | `composer install` |
| `make composer c="require foo/bar"` | arbitrary composer command |
| `make console c="app:place-order <uuid> <uuid>:2"` | arbitrary console command |
| `make migrate` / `make migrate-dry` | apply / preview migrations |
| `make consume` | consume the `async` queue (`messenger:consume async -vv`) |
| `make consume-failed` | drain the `failed` queue |
| `make cache-clear` (`make cc`) | clear the Symfony cache |
| `make ui-install` / `make ui` | install / run the frontend dev server (on the host) |
| `make ui-build` / `make ui-check` | build / type-check the frontend |

The application command:

```bash
make console c="app:place-order <customerId> <productId>:<qty> [<productId>:<qty> ...]"
```

## Manually testing the flow

Start the services and apply migrations (`make up && make migrate`), then:

**1. Populate the catalog and note a product UUID:**

```bash
make seed-products n=5
make console c="dbal:run-sql \"SELECT id, name, stock_quantity FROM products\""
```

**2. Place an order** — `customerId` is any UUID, the lines are `productId:quantity`:

```bash
make console c="app:place-order 11111111-1111-4111-8111-111111111111 <productId>:3 <otherProductId>:2"
# Order a249236f-... has been placed.
```

**3. Check what ended up in the database** — the order, the lines, the decremented stock, and the events:

```bash
make console c="dbal:run-sql \"SELECT id, status, placed_at, paid_at FROM orders\""
make console c="dbal:run-sql \"SELECT p.name, i.quantity_value, i.unit_price_amount FROM order_items i JOIN products p ON p.id = i.product_id\""
make console c="dbal:run-sql \"SELECT name, stock_quantity, version FROM products\""
make console c="dbal:run-sql \"SELECT type, status, payload FROM outbox ORDER BY occurred_at\""
```

What to look for: the order shows status `paid`, stock dropped by exactly the ordered quantities, `version` was incremented (optimistic locking), and `outbox` has two `pending` rows — `OrderPlaced` and `OrderPaid` — with the full JSON payload.

**4. Test the rollback** — the most useful part. Order a valid product together with one that has insufficient stock:

```bash
make console c="app:place-order 22222222-2222-4222-8222-222222222222 <productId>:5 <otherProductId>:99999"
# DomainException: Insufficient stock for product ...: requested 99999, available 233.
```

Then check the stock, `orders`, and `outbox` again: **nothing should have changed**. The first product's stock is intact, even though `decrementStock()` had already run on it before the second one failed. That proves `TransactionManager` does its job.

The same test works for payment too: if you change `MockPaymentGateway::charge()` to return `false`, the order fails at step 3 and the rollback restores the stock.

**Cleanup between runs:**

```bash
make console c="dbal:run-sql \"TRUNCATE order_items, orders, outbox\""
```

## Testing and quality gates

```bash
make unit          # composer test:unit    — unit tests (tests/Unit)
make test-db       # create + migrate the order_test database
make integration   # composer test:integration — runs make test-db first
make test          # the whole suite
make phpstan       # static analysis, level 6
make cs            # style check (dry-run)
make cs-fix        # apply style fixes
make check         # pre-commit gate: unit + phpstan + cs
```

**Unit tests** (`tests/Unit`, ports mocked, no I/O): `MoneyTest`, `QuantityTest`, `OrderTest`, `ProductTest`, `PlaceOrderHandlerTest`, `OutboxDomainEventDispatcherTest`.

**Integration tests** (`tests/Integration`, real PostgreSQL): `PlaceOrderHandlerTest` runs the whole use case against the `order_test` database — asserting the order, its lines, the decremented stock, the bumped optimistic-locking version and the outbox rows; that an insufficient-stock failure rolls back *everything*, including a product already decremented on an earlier line; and that an order can be hydrated back from the database. `make integration` creates and migrates `order_test` on its own, so a fresh clone needs no manual setup.

The split matters: mocked ports cannot catch anything that only breaks once Doctrine is real — custom DBAL types on identifiers, identity-map hashing, collection hydration, actual transaction boundaries. Those are exactly the failures the integration suite exists for.

> **Note on the test environment:** `phpunit.dist.xml` sets `APP_ENV=test` as both `<server>` and `<env>`. The `<env>` line is required, not redundant — `KernelTestCase` reads `$_ENV['APP_ENV']` *before* `$_SERVER['APP_ENV']`, and `docker-compose.yml` injects a real `APP_ENV=dev` into the php container. Without it the integration tests boot in dev and run against the **dev** database, which they truncate in `setUp()`.

PHPUnit runs with `failOnDeprecation`, `failOnNotice`, and `failOnWarning` enabled — any deprecation is an error.

For new code: unit tests on `Domain/`, integration tests on `Infrastructure/` and `Messaging/`, and every message handler has a test proving idempotency.

## Current status

The project is under construction; what is **not** yet implemented, so there's no confusion:

- **Outbox worker** — events are written to the `outbox` table with status `pending`, but there's no process yet that reads them and publishes them to RabbitMQ. The queues are configured and can be consumed, but nothing publishes into them.
- **Messenger buses aren't used in the flow yet** — `PlaceOrderCommand` (CLI) calls `PlaceOrderHandler` directly, not through `command.bus`. The `Application\Command\* → async` routing is ready but inactive.
- **No HTTP layer** — `UI/Controller/` is empty; the only entry point is the CLI.
- **No Query side** — `query.bus` is configured, but `Application/Query/` doesn't exist yet.
- **Payment is a mock** — `MockPaymentGateway` accepts any amount.
