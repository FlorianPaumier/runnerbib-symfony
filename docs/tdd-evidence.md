# Bootstrap TDD evidence

Approved seams: HTTP liveness and readiness using real PostgreSQL.

- Liveness red: `php vendor/bin/phpunit --filter testHealthEndpointReportsLiveness` failed because `/health` returned 404.
- Liveness green: the same command passed with 1 test and 2 assertions after implementing the endpoint.
- Readiness red: `php vendor/bin/phpunit --filter testReadinessChecksRealDatabaseConnection` failed because `/ready` returned 404.

Further container verification is recorded after the runtime checks complete.
- Readiness green: with DATABASE_URL pointing at the real PostgreSQL container, the readiness test passed (1 test, 2 assertions).
- Refactor: moved SQL connectivity behind `Application/Health/ReadinessProbe`, implemented by `Infrastructure/Health/PostgresReadinessProbe`; both HTTP tests still passed (2 tests, 4 assertions).
- Added regression coverage for an unreachable database: readiness returns 503 with no connection details. All 3 tests passed (6 assertions).
- Deptrac: 0 violations, warnings or uncovered dependencies.
- Composer strict validation, Symfony YAML lint and container lint passed.
- Development and production Compose configurations parsed successfully.
- Production PHP and Nginx images built; all three production services became healthy. HTTP `/health` and `/ready` returned 200 with their expected JSON. `bin/console about` confirmed prod, debug=false and no Xdebug; PHP ran as www-data.
- Production smoke stack stopped after verification; persistent volumes retained.
- Development PHP and Nginx images built; all four development services became healthy.
- Container `make test`: 3 tests, 6 assertions passed on PHP 8.4.26. Container `make lint`: YAML, service wiring and Deptrac passed. Container Composer strict validation passed.
- Development HTTP `/health` and `/ready` passed at localhost:8082. The existing host used port 8080, so the ignored `.env.docker` overrides HTTP_PORT to 8082; the shared example keeps 8080.

## Symfony 8.1 upgrade — 2026-10-08

- Baseline: all 3 HTTP integration tests passed before changing dependencies.
- Dependency resolution initially failed because the Composer platform was pinned to PHP 8.4.0; Symfony 8.1 packages require >=8.4.1. Updated the PHP requirement and platform to 8.4.1; Docker already runs 8.4.26.
- Composer upgraded the framework to 8.1.8 and all versioned Symfony components to 8.1.*; Doctrine remained compatible. No application behavior changes were required.
- Container tests: 3 tests, 6 assertions passed. YAML, container wiring, Deptrac and Composer strict validation passed. Composer reported no vulnerability advisories.
- Production images rebuilt successfully. Development HTTP liveness and PostgreSQL readiness passed after restarting PHP.
- Production HTTP liveness/readiness passed; console confirmed Symfony 8.1.8, prod, debug=false and PHP 8.4.26. Production smoke services stopped afterward; development remains running on port 8082.

## Coverage policy — 2026-10-08

- User requested near-100% coverage and accepted the architecture direction.
- `make coverage` on PHP 8.4.26/Xdebug 3.5.3: all 3 tests and 6 assertions passed; current bootstrap has 100% overall line coverage (10/10) and 100% Application coverage (2/2). Domain has no implemented business behavior yet.
- Coverage includes uncovered src/ files and disables coverage-ignore annotations. CI gates 98% overall and 100% Domain/Application line coverage, and uploads HTML/Clover reports. Branch coverage remains an explicit design target rather than an automated threshold.
- Gate checked with synthetic reports: complete coverage accepted, 97% overall rejected, 99% core rejected, empty report rejected. CI workflow YAML and checker PHP syntax validated.
- This bootstrap result is not coverage evidence for the planned marketplace models or provider/concurrency scenarios, which remain unimplemented.

## Quality toolchain — 2026-10-08

- Installed direct development dependencies: PHPStan 2.3.1, Symfony/PHPUnit extensions, PHP CS Fixer 3.95.27 and Infection 0.35.6. PHPUnit and Deptrac remain selected.
- Initial PHPStan level-10 run found an unsafe mixed-to-int database-result cast, three string|false JSON inputs and a redundant Dotenv compatibility guard. Replaced these with explicit accepted database-result checks, string assertions and the supported bootstrap call; no baseline or ignored errors.
- Applied Symfony formatting; subsequent dry-run found zero changes.
- `make quality` passed: PHPUnit 3 tests/9 assertions, 100% line coverage (10/10 overall, 2/2 core), Symfony lint, zero Deptrac violations, zero PHPStan errors, formatting and Composer audit (no advisories).
- Infection ran against Domain/Application with uncovered code included. Zero mutations exist in the current delegating bootstrap; zero-candidate threshold bypass is explicit and does not constitute an achieved mutation score. Future mutatable business logic is subject to 90% MSI / 95% covered MSI thresholds.
- Composer strict validation and CI workflow YAML lint passed; development HTTP liveness/readiness passed. CI invokes the same quality command and publishes both reports.
