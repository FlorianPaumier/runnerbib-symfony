# Project completion tasks

Saved 2026-10-08 for the Symfony 8.1 / PHP >=8.4.1 rebuild. **86 planned tasks across 13 files.** This is the implementation backlog, not executed acceptance evidence. French integrated web launch is the completion scope; mobile, chat and English are deferred.

## Current state

- [x] Agent instructions and red → green → refactor/Clean Architecture conventions.
- [x] Symfony 8.1, development/production Docker, liveness/readiness and quality tooling.
- [x] Layer/module directories and saved domain/race/search/privacy/reporting designs.
- [x] Complete consolidated 40-table migration with isolated apply/rollback/replay and integrity tests.
- [x] Baseline, architecture and migration commits: bebee8a, 8e1138b, 433a56c.
- [x] Apply migrations to the intended development database (BASE-01); [evidence](evidence/base-01.md).
- [ ] Implement marketplace models, application use cases, persistence adapters and French screens.

The recorded implementation baseline is 25 tests/88 assertions and 100% coverage of the small runtime bootstrap (10 lines overall, 2 core lines). This does not establish business coverage or a mutation score. Tables do not establish working financial or RGPD workflows. Future changes may require forward migrations; do not edit an already applied version.

Execution has started with specialized subagents. See [execution progress](evidence/execution-progress.md) for completed work and current dependencies.

## Delivery files

| File | Scope |
|---|---|
| [00-decisions.md](00-decisions.md) | Decisions needed before affected features ship |
| [01-foundation.md](01-foundation.md) | Runtime, persistence and web foundation |
| [02-races-and-discovery.md](02-races-and-discovery.md) | Race models, persistence and discovery |
| [03-identity-and-organizations.md](03-identity-and-organizations.md) | Accounts, authentication and organizations |
| [04-inventory-and-selling.md](04-inventory-and-selling.md) | Eligibility, ownership and verified listings |
| [05-availability-and-notifications.md](05-availability-and-notifications.md) | Shared race waitlists and notifications |
| [06-cart-and-checkout.md](06-cart-and-checkout.md) | Atomic checkout authorization and cancellation |
| [07-payments-and-sales.md](07-payments-and-sales.md) | Stripe checkout, authoritative payment and sales |
| [08-finance-and-scheduler.md](08-finance-and-scheduler.md) | Seller obligations, transfers and bank status |
| [09-support-and-organizer-follow-up.md](09-support-and-organizer-follow-up.md) | Investigations, refunds, recovery and organizer handoff |
| [10-privacy-content-and-reporting.md](10-privacy-content-and-reporting.md) | RGPD workflows, content and reports |
| [11-release-readiness.md](11-release-readiness.md) | Integrated verification and production launch |
| [12-deferred-scope.md](12-deferred-scope.md) | After web launch |

## Execution order

Start BASE-01 and BASE-02, then RACE-01 → RACE-03 → RACE-06 for an observable public race discovery slice. SELL-01 can establish fee arithmetic independently. Implement identity/organization capabilities before scoped race management. Follow task dependencies for selling, availability, atomic checkout, payment, finance and support. Finish privacy/content/reporting and integrated release evidence before deployment.

Tasks are vertical slices: domain rules, an Application interface/port, required DBAL adapters, HTTP/CLI adaptation and observable verification belong together. Do not build every model or every test in a horizontal batch. Resolve DEC tasks when the dependent feature starts; unrelated work can continue. IDs and dependencies are stable references, not a promise that independent tasks can safely mutate shared transaction code simultaneously.

## Definition of done for every implementation task

1. Confirm its public test boundary before writing tests. Existing approved seams cover HTTP health/readiness, the real PostgreSQL connection and Doctrine migration commands against isolated databases. The subsequent execute-all instruction authorizes the public Application, HTTP and controlled-provider seams required by these tasks; real provider and production acceptance still require their configured environments.
2. Observe one meaningful red test, implement the minimum green behavior, refactor with the suite green, then repeat. Expected values come from literal specification examples, not the implementation formula. Record commands and results.
3. Keep dependencies inward and authorization server-side; Domain remains framework-free, Application owns ports, Infrastructure implements them and Presentation only translates input/output. Do not create unused abstractions.
4. Test invalid input, boundary times, access denial, duplicates/retries and relevant competing transactions. Use PostgreSQL, controlled time/provider boundaries and separate real Stripe test-mode evidence as appropriate.
5. Run relevant checks and `make quality` before implementation completion. Target 100% runtime coverage; enforce >=98% src line coverage, 100% Domain/Application, meaningful business mutation thresholds and agreed performance/accessibility criteria. SQL integration evidence remains separate from runtime coverage.
6. Link requirement IDs and executed evidence, update docs/runbooks and mark the checkbox only after acceptance passes. An open decision or unverified provider/Cloud capability prevents the affected task being called complete.

Do not reset database volumes, reuse private legacy documents in test fixtures, publish production changes or send external messages merely because a task lists them. Follow the user's authorized scope and applicable project instructions. The user has authorized specialized subagent implementation of this task set. Production deployment remains a separate release action.

## Requirement and release traceability

| Requirements | Task files |
|---|---|
| PR-01/02, account/organization access; FE-02/03 | Foundation, identity/organizations, release |
| PR-03/04, discovery/import/proof/listing; FE-01/04/08 | Races, inventory/selling, support organizer follow-up |
| PR-05, shared opportunities; FE-09/10 | Availability/notifications |
| PR-06/07, atomic checkout/fees; FE-05/06/12 | Inventory/selling, checkout, payments/sales |
| PR-08/09, transfers/registration/refunds; FE-07/13 | Payments, finance/scheduler, support/follow-up |
| PR-10/11, privacy/newsletter/content; FE-14/15 | Privacy/content/reporting |
| PR-12, FE-11 and all launch requirements | Release readiness |
| ST-01–15,27–31 | Checkout, payment, concurrency and reconciliation acceptance |
| ST-16–26,29–30 | Finance, investigations, recovery, scheduler/provider acceptance |

The individual ST assignments overlap intentionally: REL-08 needs an evidence register for every ST-01–31 scenario, not just a checked task-file heading. Future mobile requirements remain outside launch acceptance.

## Sources and scope precedence

[Architecture plans](../architecture/README.md), [delivery plan](../../../specifications/delivery-plan.md), [product rules](../../../specifications/product-rules.md), [backend](../../../specifications/backend/specification.md), [frontend](../../../specifications/frontend/specification.md), [Stripe acceptance scenarios](../../../specifications/backend/stripe-states-and-tests.md) and [open decisions](../../../specifications/decisions-and-gaps.md).

The user's later selection of Symfony 8.1 governs this project despite older Symfony 8.2 references. Keep PostgreSQL/Redis/Laravel Cloud and Twig/UX/Tailwind directions. The executed 40-table migration and finalized race contract supersede the earlier 64-table inventory. Optional daily_metrics, privacy-request tables or further search infrastructure require a concrete justified use case, not automatic inclusion.
