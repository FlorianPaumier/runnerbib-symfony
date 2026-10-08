# Accepted testing and quality plan

Saved 2026-10-08. The user approved this toolchain and near-100% coverage requirement. The tools are configured in Docker and CI; marketplace behavior is still to be implemented.

| Decision | Accepted approach |
|---|---|
| Test runner | PHPUnit, Symfony KernelTestCase/WebTestCase; PostgreSQL integration tests for persistence/concurrency |
| Workflow | One public behavior at a time: observe red, implement green, refactor with a green suite |
| Coverage | Target 100%; CI minimum 98% src/ line coverage and 100% Domain/Application; include uncovered files and disable coverage-ignore annotations |
| Branches | Aim for 100% business-rule branch coverage; current automatic threshold is line coverage |
| Static analysis | PHPStan level 10 over src/, tests/ and tools/ with Symfony/PHPUnit extensions; no baseline or ignored errors |
| Formatting | PHP CS Fixer with Symfony rules; check in CI, apply through make cs-fix |
| Architecture | Deptrac: Domain/Application remain free of framework and concrete adapter dependencies |
| Mutation testing | Infection on Domain/Application, minimum 90% MSI and 95% covered MSI; inspect survivors; no candidates means no achieved score |
| Dependency audit | Composer audit in CI |
| Combined gate | make quality; CI publishes coverage and mutation reports |

Tests exercise observable outcomes and independent expected values. Internal mocks or assertions that merely execute lines do not satisfy the requirement. Payment reconciliation, authorization, competing purchases, retries and crash recovery remain acceptance scenarios even at 100% line coverage.

The existing approved seams are HTTP health/readiness and actual database connectivity. Future feature seams are proposed in the [model plan](domain-model-plan.md); agree them before writing feature tests. No new tests or business implementation are authorized merely by saving this plan.

Current executed results and limitations are in [TDD evidence](../tdd-evidence.md). In particular, the core bootstrap has no meaningful mutation candidates yet. Do not represent bootstrap coverage as coverage of unimplemented marketplace models.
