# Roomtone tasks. `make` lists them.
#
#   make rebuild   clear the framework caches, reinstall dependencies and rebuild the front end (everyday dev)
#   make deploy    tests, a full rebuild, then the Docker image from scratch and the container restarted
#
# The app cache is NOT cleared by default: it holds every Qloo answer (cached forever, they cost API quota) and the
# YouTube quota flag. `make clear-app-cache` wipes it on purpose.

COMPOSE  ?= docker compose
SKIP_TESTS ?=

.DEFAULT_GOAL := help
.PHONY: help clear clear-app-cache install frontend rebuild test check image up deploy logs

help: ## List the tasks
	@grep -E '^[a-z-]+:.*##' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  make %-16s %s\n", $$1, $$2}'

clear: ## Clear config, route, view and event caches, compiled files and the front end's build cache
	php artisan config:clear
	php artisan route:clear
	php artisan view:clear
	php artisan event:clear
	php artisan clear-compiled
	rm -rf public/build node_modules/.vite bootstrap/ssr

clear-app-cache: ## Wipe the application cache too: Qloo answers (they cost quota to refetch) and the YouTube quota flag
	php artisan cache:clear

install: ## Install PHP and JS dependencies exactly as locked
	composer install --no-interaction --prefer-dist
	npm ci

frontend: ## Build the front end (client and SSR bundles; also regenerates the Wayfinder routes)
	php artisan wayfinder:generate
	npm run build:ssr

rebuild: clear install frontend ## Clear caches, reinstall dependencies, rebuild the front end, migrate
	composer dump-autoload --no-interaction
	php artisan migrate --force

test: ## Run the Pest suite
	php artisan test

check: ## Pint, TypeScript and the JS linter, without changing files
	vendor/bin/pint --test
	npm run types:check
	npx vp check

image: ## Build the Docker image from scratch (no layer cache; it builds the front end itself)
	$(COMPOSE) build --no-cache

up: ## Recreate the container from the image and wait until it answers
	$(COMPOSE) up -d --force-recreate
	@printf 'Waiting for http://localhost/up '
	@for i in $$(seq 1 60); do \
		if curl -fs -o /dev/null http://localhost/up; then echo ' up.'; exit 0; fi; \
		printf '.'; sleep 1; \
	done; echo ' not answering after 60s: $(COMPOSE) logs app'; exit 1

deploy: $(if $(SKIP_TESTS),,test) rebuild image up ## Tests, full rebuild, fresh Docker image, restart (SKIP_TESTS=1 skips the tests)
	@echo 'Deployed: http://localhost'

logs: ## Follow the container's logs
	$(COMPOSE) logs -f app
