DC = docker compose
PHP = $(DC) exec php

.PHONY: help up down build logs sh migrate import import-async update-prices worker test phpstan cs cs-fix

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

up: ## Start the whole stack (db, php, nginx, worker)
	$(DC) up -d --build

down: ## Stop and remove containers
	$(DC) down

build: ## Rebuild images
	$(DC) build

logs: ## Tail logs
	$(DC) logs -f

sh: ## Shell into the php container
	$(PHP) sh

migrate: ## Run database migrations
	$(PHP) php bin/console doctrine:migrations:migrate --no-interaction

import: ## Import the sample feed synchronously
	$(PHP) php bin/console app:import-products products.json

import-async: ## Queue the sample feed for the worker
	$(PHP) php bin/console app:import-products products.json --async

update-prices: ## Adjust all prices by P percent, e.g. make update-prices P=10
	$(PHP) php bin/console app:update-prices $(P)

worker: ## Run a messenger worker in the foreground
	$(PHP) php bin/console messenger:consume async failed -vv

test: ## Run the test suite (inside the php container, against the db service)
	$(PHP) sh -lc "export APP_ENV=test API_KEY=test_api_key DATABASE_URL='postgresql://app:app@db:5432/app_test?serverVersion=16&charset=utf8'; php bin/console doctrine:database:create --if-not-exists --env=test; vendor/bin/phpunit"

phpstan: ## Run static analysis
	$(PHP) sh -lc "php bin/console cache:warmup --env=dev && vendor/bin/phpstan analyse --no-progress"

cs: ## Check coding standards
	$(PHP) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ## Fix coding standards
	$(PHP) vendor/bin/php-cs-fixer fix
