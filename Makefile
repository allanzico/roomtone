# Roomtone: short commands for the Docker setups. Local and production are separate compose projects with their
# own volumes and env files (compose.local.yaml + .env.docker, compose.prod.yaml + .env.production).
LOCAL = docker compose -f compose.local.yaml --env-file .env.docker
PROD  = docker compose -f compose.prod.yaml --env-file .env.production

.PHONY: local-up local-down local-logs local-shell local-demo local-reset deploy prod-logs prod-shell prod-backup prod-demo smoke

local-up:      ## Build and start the local stack at http://localhost:8088
	$(LOCAL) up -d --build --wait
local-down:    ## Stop the local stack (data kept)
	$(LOCAL) down
local-reset:   ## Stop the local stack and delete its data
	$(LOCAL) down -v
local-logs:
	$(LOCAL) logs -f
local-shell:
	$(LOCAL) exec web sh
local-demo:    ## Seed the fake demo party (no API calls)
	$(LOCAL) exec web php artisan db:seed --class=DemoPartySeeder --force

deploy:        ## Deploy production (./deploy.sh)
	./deploy.sh
prod-logs:
	$(PROD) logs -f --tail=100
prod-shell:
	$(PROD) exec web sh
prod-backup:
	$(PROD) exec web php artisan roomtone:backup
prod-demo:     ## Build the real demo party from live Qloo, Anthropic and YouTube calls
	$(PROD) exec web php artisan roomtone:demo:build

smoke:         ## Build and test a throwaway stack end to end (scripts/smoke.sh)
	scripts/smoke.sh
