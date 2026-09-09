# AGENTS.md

Order-processing app — Symfony 8.1 / PHP 8.4, PostgreSQL (Doctrine), RabbitMQ (Messenger), modular monolith. Context: orders, product stock, payments.

## Architecture

One directory per bounded context under `src/` (currently only `Orders/`, namespace `App\Orders\`):

```
src/Orders/
  Domain/
    Model/            # Entities + value objects. Pure: no Symfony, no Doctrine, no AMQP.
    Event/            # Domain events, past tense (OrderPlaced, OrderPaid, ProductOutOfStock...)
    Port/             # Interfaces (OrderRepository, ProductRepository, PaymentGateway)
  Application/
    Command/          # Commands (VerbNoun) + handlers (use-case orchestration)
    Port/             # Application ports (DomainEventDispatcher)
  Infrastructure/
    Persistence/      # Doctrine impls: repositories, Mapping/ (XML), Type/ (custom DBAL types)
    Messaging/        # AMQP impls (MockPaymentGateway, OutboxDomainEventDispatcher)
  UI/
    Command/          # bin/console commands
    Controller/       # HTTP controllers
```

Dependencies flow inwards. Domain never imports from Application, Infrastructure, or UI.

## Design rules (apply, do not explain)

- Entities = identity + behavior. Value objects = immutable (Money, Quantity...).
- Business logic in Domain, not in controllers/anemic entities.
- Repository/gateway = interface in `Domain/Port/`, Doctrine implementation in `Infrastructure/Persistence/`.
- Command (change) vs Query (read): separate buses in messenger.yaml. Existing buses: `command.bus` (default), `query.bus`, `event.bus` — no new bus without reason.
- Inject interfaces, not concrete Doctrine/AMQP classes, into services and handlers.
- Small, use-case-specific interfaces — no god repositories.

## Messenger / RabbitMQ

- Buses: `command.bus` (default, routed `async`), `query.bus` (`sync`), `event.bus` (allow_no_handlers). Failure transport `failed`: 3 retries, backoff ×2, cap 10s.
- Every consumer must be idempotent (same message 2× is expected). Deduplicate via message_id.
- Outbox pattern when an action must be atomic with a published event: write to the `outbox` table in the same DB transaction; a separate worker publishes (see `OutboxDomainEventDispatcher`).
- Message payload = contract; backward-compatible changes (add fields, don't rename/delete).
- Log message_id + correlation_id on consumption.

## PostgreSQL / Doctrine

- Schema changes ONLY via Doctrine migrations (`bin/console make:migration`), never manually.
- No `findAll()`/full hydration on large lists — query builder + pagination.
- Indexes on frequent WHERE/JOIN columns, including dedup/outbox columns.
- Explicit transactions for any operation that must be atomic.

## Code

- `declare(strict_types=1);` everywhere, no avoidable `mixed`.
- PSR-12. Commands `VerbNoun`, events past tense (`OrderPlaced`), handlers `{Message}Handler`.
- New code → tests: unit on Domain, integration on Infrastructure/Messaging.
- Every message handler has a test proving idempotency (message 2× → single effect).

## Local setup

- `docker compose up -d` → php (port 8000), postgres (5432), rabbitmq (5672 + management UI 15672).
- Env: `.env` (dev), `.env.dev`, `.env.test` — `DATABASE_URL`, `MESSENGER_TRANSPORT_DSN`.
- Consume queues: `bin/console messenger:consume async -vv` (add `failed` to drain failures).

## Verification

```
composer install
composer test:unit
composer test:integration
composer phpstan
composer cs-fixer
bin/console doctrine:migrations:migrate --dry-run
```

## Forbidden

- DB schema modification without a migration.
- Symfony/Doctrine/AMQP dependencies in Domain/.
- Message publishing + DB writing in the same step without transaction/outbox.
- Massive refactoring in a single commit — small, reversible changes.
- Assumptions on bounded-context ambiguities — ask instead of inventing.
