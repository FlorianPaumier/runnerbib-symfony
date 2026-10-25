# BASE-01 — Development schema applied

Executed on 2026-10-08 in `symfony-app` against the existing `runnerbib-symfony` Docker Compose development services. No volumes were reset and no other application database was modified.

## Preflight

- `docker compose --env-file .env.docker ps`: all four development services healthy; web bound to local port 8082.
- Read-only PostgreSQL inspection through the `database` service: connected database `app`, user `app`; zero public tables before migration bookkeeping initialization.
- `make migration-status`: current version 0, exactly one pending version, `DoctrineMigrations\Version20261008000100`, no unavailable executed versions.
- `make migration-dry-run`: succeeded, reported one migration and 111 SQL queries. This was a dry run, not evidence of an applied schema.

## Apply and verification

- `make migrate`: succeeded, one migration executed, 109 SQL queries; latest version `DoctrineMigrations\Version20261008000100`.
- `make migration-status`: one executed version, zero new versions, already at latest.
- Read-only PostgreSQL query: `SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename <> 'doctrine_migration_versions'` returned **40** marketplace tables. Migration metadata stores the exact expected version. `SELECT count(*) FROM races` returned 0.
- `curl --fail --silent --show-error http://127.0.0.1:8082/ready`: succeeded with `{"status":"ready"}`.
- `make test`: succeeded, **25 tests, 88 assertions**. Existing migration tests use their isolated temporary databases, including rollback/replay; the development database remains installed.

This task applies an already implemented and tested migration; it introduces no new behavior or schema change, so no new red-green cycle is claimed. Production deployment and restricted runtime roles remain separate tasks. Database readiness verifies connectivity; the table count and migration command verify schema installation.
