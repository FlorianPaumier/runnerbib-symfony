DC = docker compose $(if $(wildcard .env.docker),--env-file .env.docker,)

.PHONY: up down build install test coverage lint analyse cs-check cs-fix mutation audit quality logs shell reset prod-build
up: build install
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
test:
	$(DC) exec -e APP_ENV=test php vendor/bin/phpunit
coverage:
	$(DC) exec -e APP_ENV=test -e XDEBUG_MODE=coverage php vendor/bin/phpunit --coverage-clover var/coverage/clover.xml --coverage-html var/coverage/html
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
mutation:
	$(DC) exec -e APP_ENV=test -e XDEBUG_MODE=coverage php vendor/bin/infection --no-progress --no-interaction --with-uncovered --ignore-msi-with-no-mutations
audit:
	$(DC) exec php composer audit
quality: coverage lint analyse cs-check mutation audit
