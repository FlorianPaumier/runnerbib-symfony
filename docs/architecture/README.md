# Saved architecture plans

Saved 2026-10-08 for the Symfony 8.1 rebuild.

1. [Domain model architecture](domain-model-plan.md): accepted architectural direction, model boundaries, application interfaces and delivery slices. Concrete names/states remain design proposals.
2. [Testing and quality](quality-plan.md): accepted PHPUnit, coverage thresholds, PHPStan level 10, formatting, architecture and mutation checks.
3. [PostgreSQL schema plan](database-schema-plan.md): proposed tables, keys, constraints, indexes, transaction boundaries and migration sequence. No migrations have been applied.

The authoritative business sources live in [workspace specifications](../../../specifications/README.md). Open business policies remain open; the latest user instruction selects Symfony 8.1 for this project despite the earlier 8.2 target in those specifications. Saving these plans does not commit or deploy the repository.

4. [Schema size review](schema-size-review.md): existing Symfony models compared with the 64-table inventory; proposed consolidation to a 40-table full-scope candidate.
5. [Search, privacy and reporting](search-privacy-reporting-plan.md): search indexes, private-data lifecycle and aggregate/financial reporting requirements for the simplified candidate.
6. [Finalized race schema](race-schema.md): race columns, invariants, discovery indexes, legacy mapping and implementation boundaries; supersedes earlier race-table sketches.
7. [Module boundaries](module-boundaries.md): concrete layer/module layout and migration ownership.
