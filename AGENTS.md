# Symfony project agent instructions

When present, inherit workspace `../AGENTS.md`. Local role instructions live in `.agents/roles/`; they do not automatically authorize delegation. Use Symfony 8.1 and PHP 8.4.1+. Follow red → green → refactor for each behavior; do not implement business behavior before observing its failing test.

## Boundaries
- `src/Domain/`: framework-free business entities, value objects and rules.
- `src/Application/`: use cases and ports; depends only on Domain and PHP.
- `src/Infrastructure/`: adapters for Doctrine, mail and external systems.
- `src/Presentation/`: Symfony HTTP controllers and CLI entry points.
- `src/Kernel.php` and `config/`: composition root and framework integration.

Approved initial test seams: HTTP health endpoints and the real PostgreSQL connection. `/health` proves liveness; `/ready` proves database readiness. Put HTTP integration tests in `tests/Integration/`, domain tests in `tests/Unit/` when domain behavior exists. Use explicit application ports and inject adapters at the composition root.

## Commands
`make up`, `make test`, `make lint`, `make logs`, `make down`. Use container PHP for consistent extensions. Run `make test` after each green/refactor step. Validate both Compose configurations and build both Docker targets after infrastructure changes. Never run `make reset` without explicit approval: it removes database volumes.

## Review
Check dependency direction, HTTP error responses, production debug settings, runtime file permissions and secret handling. Report actual validation; do not assume an image builds because Compose parses. Keep documentation in README.md current.

Domain must not import Symfony, Doctrine, Psr or other vendor classes. Application ports belong to Application. Infrastructure implements ports; Presentation invokes use cases, never concrete persistence adapters. Test observable behavior and use literal expected results. Never commit credentials or generated files. Report commands and actual results.

## Coverage policy
Target 100% coverage. CI requires at least 98% line coverage across all hand-written code in src/, including uncovered files, and 100% line coverage in Domain and Application. Cover branches, boundaries, invalid inputs, authorization, concurrency, retries and recovery through public interfaces; aim for 100% branch coverage in business rules. Coverage percentages do not replace scenario/concurrency/provider evidence. Do not exclude difficult business code or add assertions that merely execute lines. Any justified generated-code exclusion must be documented and reviewed. Run `make coverage` before completing implementation work; inspect uncovered paths and report actual measurements.

## Quality gates
Use PHPUnit. `make quality` runs coverage, Symfony/Deptrac lint, PHPStan level 10, PHP CS Fixer dry-run, Infection and Composer audit. Analyze src/, tests/ and tools/ without a baseline or ignored errors. Fix actual types/guards instead of using unverified PHPDoc casts. Use `make cs-fix` for the Symfony formatting rules. Infection initially targets Domain/Application, with minimum 90% MSI and 95% covered MSI; inspect every survivor. With zero mutation candidates the score is not applicable, not 100%. Do not exclude business code or disable mutators to obtain a passing score. New business rules must have meaningful mutation candidates.

## Migration test boundary
The user approved Doctrine migration commands and real isolated PostgreSQL databases for apply, rollback, replay and constraint tests. MigrationsTest creates uniquely named migration_test_* databases and drops only those test databases. Development/production data must not be reset for verification. Migration PHP and SQL are deployment infrastructure outside src/ coverage; analyze/style-check migration PHP and verify SQL through the migration integration tests.
