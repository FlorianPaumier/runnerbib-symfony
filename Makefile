DOCKER_PLATFORM ?= $(shell docker version --format '{{.Server.Os}}/{{.Server.Arch}}')
DC = DOCKER_DEFAULT_PLATFORM=$(DOCKER_PLATFORM) docker compose $(if $(wildcard .env.docker),--env-file .env.docker,)

.PHONY: up down build install test test-serial coverage lint analyse cs-check cs-fix mutation audit quality logs shell reset prod-build
up: assets build install
	$(DC) up -d --wait
build:
	$(DC) build
install:
	$(DC) run --rm --no-deps php composer install --no-interaction
down:
	$(DC) down
logs:
	$(DC) logs -f --tail=100
shell:
	$(DC) exec php sh
TEST_PROCESSES ?= auto
TEST_MAX_SECONDS ?= 120
TEST_DATABASE_URL ?= postgresql://test:local-test-only@database-test:5432/test?serverVersion=17&charset=utf8
TEST_ENV = -e APP_ENV=test -e 'DATABASE_URL=$(TEST_DATABASE_URL)'
TEST_DATABASE_UP = $(DC) --profile test up -d --wait database-test

.PHONY: test-database
test-database:
	$(TEST_DATABASE_UP)

test:
	@started=$$(date +%s); \
	$(TEST_DATABASE_UP) || exit $$?; \
	$(DC) exec $(TEST_ENV) -e XDEBUG_MODE=off php php -d opcache.enable_cli=1 -d opcache.memory_consumption=256 vendor/bin/paratest --functional --processes=$(TEST_PROCESSES) --passthru-php='-d opcache.enable_cli=1 -d opcache.memory_consumption=256'; status=$$?; \
	elapsed=$$(($$(date +%s) - started)); \
	echo "Test suite wall time: $${elapsed}s (budget: $(TEST_MAX_SECONDS)s)"; \
	if [ $$status -ne 0 ]; then exit $$status; fi; \
	if [ $$elapsed -ge $(TEST_MAX_SECONDS) ]; then echo "Test suite exceeded the $(TEST_MAX_SECONDS)s budget." >&2; exit 1; fi

test-serial: test-database
	$(DC) exec $(TEST_ENV) -e XDEBUG_MODE=off php vendor/bin/phpunit
coverage: test-database
	$(DC) exec $(TEST_ENV) -e XDEBUG_MODE=coverage php php -d opcache.enable_cli=1 -d opcache.memory_consumption=256 vendor/bin/paratest --functional --processes=$(TEST_PROCESSES) --passthru-php='-d opcache.enable_cli=1 -d opcache.memory_consumption=256' --coverage-clover var/coverage/clover.xml --coverage-html var/coverage/html --coverage-xml var/coverage/coverage-xml --log-junit var/coverage/junit.xml
	$(DC) exec php php tools/check-coverage.php var/coverage/clover.xml
lint:
	$(DC) exec php bin/console lint:yaml config
	$(DC) exec php bin/console lint:container
	$(DC) exec php vendor/bin/deptrac analyse --no-progress
reset:
	$(DC) down --volumes
prod-build:
	docker compose --env-file .env.docker -f compose.prod.yaml build

analyse:
	$(DC) exec php bin/console cache:warmup --env=dev
	$(DC) exec php vendor/bin/phpstan analyse --no-progress --memory-limit=512M
cs-check:
	$(DC) exec php vendor/bin/php-cs-fixer fix --dry-run --diff
cs-fix:
	$(DC) exec php vendor/bin/php-cs-fixer fix
mutation: coverage
	$(DC) exec $(TEST_ENV) -e XDEBUG_MODE=off php vendor/bin/infection --with-timeouts --max-timeouts=0 --coverage=var/coverage --skip-initial-tests --only-covering-test-cases --no-progress --no-interaction --with-uncovered --ignore-msi-with-no-mutations	$(DC) exec php sh -c 'grep -qx "Skipped: 0" var/infection/summary.log || { echo "Mutation check refused: some candidates were skipped." >&2; exit 1; }'
audit:
	$(DC) exec php composer audit
quality: coverage lint analyse cs-check mutation audit

.PHONY: migrate migration-status migration-dry-run
migrate:
	$(DC) exec php bin/console doctrine:migrations:migrate --no-interaction
migration-status:
	$(DC) exec php bin/console doctrine:migrations:status
migration-dry-run:
	$(DC) exec php bin/console doctrine:migrations:migrate --dry-run --no-interaction

.PHONY: assets assets-watch
assets:
	docker run --rm -v "$(CURDIR):/app" -v runnerbib-assets-node-modules:/app/node_modules -w /app oven/bun:1.3.1-alpine sh -c 'bun install --frozen-lockfile && bun run --bun assets:build'
assets-watch:
	bun run assets:watch

.PHONY: outbox worker failed-messages availability reconcile-checkouts sellers provider-events retention
reconcile-checkouts:
	$(DC) exec php bin/console app:checkout:reconcile --limit=100
availability:
	$(DC) exec php bin/console app:availability:run --limit=100
outbox:
	$(DC) exec php bin/console app:outbox:dispatch --limit=100
worker:
	$(DC) exec php bin/console messenger:consume async --time-limit=3600 --memory-limit=128M
failed-messages:
	$(DC) exec php bin/console messenger:failed:show
sellers:
	$(DC) exec php bin/console app:sellers:reconcile --limit=50
provider-events:
	$(DC) exec php bin/console app:provider-events:process --limit=100
retention:
	$(DC) exec php bin/console app:retention:purge

.PHONY: fixtures
fixtures:
	$(DC) exec --user www-data -e FIXTURE_PASSWORD php php tools/load-dev-fixtures.php
