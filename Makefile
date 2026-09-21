.PHONY: help build up down logs shell install test unit integration phpstan cs cs-fix migrate migrate-dry consume consume-failed cache-clear cc check console composer seed-products seed-orders

# Load .env so targets can use its variables (APP_ENV, DATABASE_URL, ...).
# Real environment variables still win over these.
-include .env
export

# Everything PHP runs inside the php container.
DC       := docker compose
EXEC     := $(DC) exec php
CONSOLE  := $(EXEC) php bin/console
COMPOSER := $(EXEC) composer

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

## Docker

build: ## Build docker compose images
	$(DC) build

up: ## Start all services (php, postgres, rabbitmq) in background
	$(DC) up -d

down: ## Stop all services
	$(DC) down

shell: ## Shell inside the php container (alpine -> sh)
	$(EXEC) bash

## Dependencies

install: ## Install PHP dependencies (in the container)
	$(COMPOSER) install

composer: ## Run an arbitrary composer command, e.g. make composer c="require foo/bar"
	$(COMPOSER) $(c)

## Quality gates

test: ## Run all tests (requires DB for integration: make up)
	$(COMPOSER) test

unit: ## Run unit tests only
	$(COMPOSER) test:unit

integration: ## Run integration tests only (requires DB: make up)
	$(COMPOSER) test:integration

phpstan: ## Static analysis
	$(COMPOSER) phpstan

cs: ## Check code style (dry-run)
	$(COMPOSER) cs-fixer

cs-fix: ## Fix code style
	$(COMPOSER) cs-fixer:fix

check: unit phpstan cs ## Pre-commit gate: unit tests + phpstan + style check

## Database / messaging

migrate: ## Apply Doctrine migrations
	$(CONSOLE) doctrine:migrations:migrate

migrate-dry: ## Preview pending migrations without applying
	$(CONSOLE) doctrine:migrations:migrate --dry-run

consume: ## Consume the async messenger queue (ctrl-c to stop)
	$(CONSOLE) messenger:consume async -vv

consume-failed: ## Drain the failed queue
	$(CONSOLE) messenger:consume failed -vv

## Seed data (dev only)

seed-products: ## Seed the product catalog, e.g. make seed-products n=20 seed=123
	$(EXEC) bash bin/seed-products.sh $(or $(n),10) $(seed)

seed-orders: ## Place n random orders via app:place-order, e.g. make seed-orders n=50 seed=123
	$(EXEC) bash bin/seed-orders.sh $(or $(n),10) $(seed)

## Misc

console: ## Run an arbitrary console command, e.g. make console c="app:place-order <uuid> <uuid>:2"
	$(CONSOLE) $(c)

cache-clear: ## Clear Symfony cache
	$(CONSOLE) cache:clear --env=$(APP_ENV)

cc: cache-clear ## Alias for cache-clear
