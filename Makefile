.PHONY: help build up down logs shell install test unit integration phpstan cs cs-fix migrate migrate-dry consume consume-failed cache-clear cc check

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

## Docker

build: ## Build docker compose images
	docker compose build

up: ## Start all services (php, postgres, rabbitmq) in background
	docker compose up -d

down: ## Stop all services
	docker compose down

logs: ## Tail logs of all services
	docker compose logs -f

shell: ## Bash inside the php container
	docker compose exec php bash

## Dependencies

install: ## Install PHP dependencies
	php composer.phar install

## Quality gates

test: ## Run all tests (requires DB for integration: make up)
	php composer.phar test

unit: ## Run unit tests only
	php composer.phar test:unit

integration: ## Run integration tests only (requires DB: make up)
	php composer.phar test:integration

phpstan: ## Static analysis
	php composer.phar phpstan

cs: ## Check code style (dry-run)
	php composer.phar cs-fixer

cs-fix: ## Fix code style
	composer cs-fixer:fix

check: unit phpstan cs ## Pre-commit gate: unit tests + phpstan + style check

## Database / messaging

migrate: ## Apply Doctrine migrations
	bin/console doctrine:migrations:migrate

migrate-dry: ## Preview pending migrations without applying
	bin/console doctrine:migrations:migrate --dry-run

consume: ## Consume the async messenger queue (ctrl-c to stop)
	bin/console messenger:consume async -vv

consume-failed: ## Drain the failed queue
	bin/console messenger:consume failed -vv

## Misc

cache-clear: ## Clear Symfony cache
	bin/console cache:clear

cc: cache-clear ## Alias for cache-clear
