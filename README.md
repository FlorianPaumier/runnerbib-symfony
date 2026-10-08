# Runnerbib Symfony application

Symfony 8.1 on PHP 8.4 (minimum 8.4.1), with Nginx, PostgreSQL 17 and Mailpit. Agent rules are in `AGENTS.md` and the workspace `../AGENTS.md`; role instructions are in `.agents/roles/`.

## Local development

Requirements: Docker Engine/Desktop with Docker Compose and Make. Host PHP and Composer are optional.

```sh
cp .env.docker.example .env.docker
make up
make test
make coverage
make lint
```

If port 8080 is occupied, change `HTTP_PORT` in `.env.docker` before starting. This workspace currently uses port 8082.

Open http://localhost:8080/health for liveness and http://localhost:8080/ready for database readiness. `/ready` returns HTTP 503 when PostgreSQL is unavailable. The application has no homepage yet. Mailpit is at http://localhost:8025; SMTP is `mailpit:1025` within Docker. PostgreSQL and PHP-FPM have no host ports.

`make up` builds images, installs locked Composer dependencies into a named volume and waits for services. Source is bind-mounted; vendor and Symfony runtime files use named volumes. After changing composer.lock, run `make install`. Use `make down` to stop services while preserving data. `make reset` removes **all development volumes, including the database**.

```sh
docker compose exec php bin/console about
docker compose exec php composer require some/package
make logs
make shell
```

Make uses `.env.docker` automatically when present. For direct Compose commands, pass `--env-file .env.docker` when using customized settings. Database credentials in the example are for local development. Passwords included in DATABASE_URL must be URI encoded; use URL-safe secrets with this Compose template.

Enable debugging with `XDEBUG_MODE=debug` in `.env.docker`, recreate PHP, listen on port 9003 in your IDE and map `/app` to this directory. Xdebug starts on a request trigger. Set `XDEBUG_MODE=coverage` for coverage runs. Xdebug is absent from production.

## Architecture and TDD

Saved decisions and plans are indexed in [architecture plans](docs/architecture/README.md), including the [quality plan](docs/architecture/quality-plan.md) and [PostgreSQL schema plan](docs/architecture/database-schema-plan.md).

The proposed [domain model architecture plan](docs/architecture/domain-model-plan.md) defines module ownership, aggregates, transaction boundaries, persistence safeguards and the TDD implementation sequence.

Dependencies point inward: Presentation invokes Application, Application owns ports and depends on Domain, and Infrastructure implements those ports. Symfony configuration wires adapters. Domain is intentionally empty until real business behavior is requested; vocabulary comes from `../specifications/glossary.md`.

Write one behavioral test, observe red, implement the smallest green change, then refactor while tests stay green. Initial agreed seams are the HTTP health endpoints and real PostgreSQL connectivity. Tests make no data changes. Future persistence tests need an isolated test database. Run `make lint` for container/YAML validation and Deptrac architecture checks.

Coverage targets 100%. `make coverage` enables Xdebug for the test process, includes uncovered source files and enforces **98% minimum line coverage overall**, with **100% in Domain/Application**. HTML reports are in the runtime volume at `/app/var/coverage/html`. To view locally, run `docker compose cp php:/app/var/coverage ./coverage` (add your environment-file option if needed). CI uploads the reports. Cover business-rule branches and failure/recovery scenarios as well as lines; the initial gate measures line coverage.

## Quality tools

Run `make quality` before completing a change. Individual commands:

| Command | Check |
|---|---|
| `make coverage` | PHPUnit/Xdebug; >=98% overall lines, 100% Domain/Application |
| `make lint` | Symfony configuration/services and Deptrac dependency direction |
| `make analyse` | PHPStan level 10 over src/, tests/ and tools/, with Symfony/PHPUnit extensions; no baseline |
| `make cs-check` / `make cs-fix` | Check/apply Symfony PHP formatting |
| `make mutation` | Infection over Domain/Application; >=90% MSI and >=95% covered MSI |
| `make audit` | Composer dependency vulnerabilities |

PHPStan level 10 includes lower-level checks and rejects unsafe operations on explicit or inferred `mixed`. Narrow unknown data with real validation before using it. It analyzes code without executing it; business scenarios still need tests.

Infection changes production code temporarily and checks that tests detect those changes. The current core bootstrap only delegates to a readiness port, so there are no mutation candidates yet: this is **not an achieved mutation score**. The zero-candidate exception is explicit; future business logic must produce meaningful candidates and satisfy the thresholds. Uncovered core code is included in mutation analysis. CI uploads coverage and mutation HTML reports.

## Production

`compose.prod.yaml` is standalone. It contains immutable application and web images, locked dependencies without development packages, no source mounts, no Mailpit, persistent PostgreSQL storage and a PHP runtime volume. PHP runs as www-data with debug disabled and warms its cache at startup. Migrations are a separate deployment step once added; startup never changes schema.

Create a deployment-specific environment file outside version control, with a strong APP_SECRET, a URL-safe POSTGRES_PASSWORD and a real MAILER_DSN. Then:

```sh
docker compose --env-file /path/to/production.env -f compose.prod.yaml config --quiet
docker compose --env-file /path/to/production.env -f compose.prod.yaml build --pull
docker compose --env-file /path/to/production.env -f compose.prod.yaml up -d --wait
```

The production web port defaults to loopback port 8081. Put a TLS reverse proxy in front of it and configure trusted proxies before deployment. Back up PostgreSQL with your deployment tooling. Service health checks cover FPM liveness and HTTP liveness; deployment readiness should additionally request `/ready`. Supply secrets through your deployment environment. Rebuild both PHP and web images when assets change. Pin image digests in a deployment pipeline for reproducible releases.

Official framework setup: https://symfony.com/doc/8.1/setup.html
