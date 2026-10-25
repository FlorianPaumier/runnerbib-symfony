# Integrated verification and production launch

Status: planned, 2026-10-08. Finish means a working accepted French web application, not only models, migrations or checked task documents.

Use the [task index and completion rules](README.md). Dependencies reference task IDs, not automatic authorization to execute another task.

## REL-01 — Integrated core web journeys

- [ ] Complete this task and link executed evidence.

Dependencies: RACE-06, AUTH-06, SELL-05, AV-04, CHECK-05, SALE-02, FIN-06, SUPPORT-05, PRIV-04, CONTENT-01, SELL-04.

Deliver: Run browser/backend journeys for signup, organizer import, proof/onboarding, listing/waitlist, whole checkout, ownership/history/relisting, funds, support and privacy.

Acceptance: Real backend authorization and Stripe test mode replace fake-success screens; all FE requirements are mapped to observed evidence.

## REL-02 — Coverage, mutation and regression gates

- [ ] Complete this task and link executed evidence.

Dependencies: REL-01.

Deliver: Run make quality; inspect uncovered branches and mutation survivors; retain HTTP/DB/provider/concurrency scenarios for business invariants.

Acceptance: Target 100%, gates >=98% src lines and 100% Domain/Application; business-branch target 100%; Infection >=90% MSI/95% covered MSI with real mutants; no exclusions or no-mutant score claims.

## REL-03 — Accessibility and measured performance

- [ ] Complete this task and link executed evidence.

Dependencies: REL-01, RACE-05, DEC-08.

Deliver: Audit keyboard/forms/focus/errors/contrast/responsive layouts and agreed accessibility target; measure representative backend/search/report load.

Acceptance: Record findings/fixes and accepted budgets; no reliance on legacy scale claims or line coverage as performance evidence.

## REL-04 — Staging infrastructure and security validation

- [ ] Complete this task and link executed evidence.

Dependencies: BASE-06, FIN-07, PRIV-03.

Deliver: Exercise Cloud web/workers/schedulers/queue/storage, production assets, secret rotation, CSRF/session controls, role/resource attacks and graceful deployment/restart.

Acceptance: Runtime role cannot own DDL; private objects remain private; redis/queue failures cannot lose durable protections; new image cache/migrations are explicit.

## REL-05 — Restore, replay and disaster recovery rehearsal

- [ ] Complete this task and link executed evidence.

Dependencies: REL-04.

Deliver: Restore PostgreSQL and private storage into an isolated staging environment, reconcile existing provider effects and honor prior erasures.

Acceptance: Restore/replay emits no duplicate payment/transfer/ownership effect; object references and retained/deleted evidence are consistent; recovery steps and agreed recovery targets are measured.

## REL-06 — Monitoring and runbooks

- [ ] Complete this task and link executed evidence.

Dependencies: REL-04, FIN-04, PAY-04.

Deliver: Configure liveness/readiness, missed scheduler/worker alerts, unknown payment/transfer escalation, failure queues, hold exposure and sanitized logs.

Acceptance: Exercise alerts and safe replay/reconciliation/runbook paths; three-failure alert semantics remain correct; operational ownership and secrets/access are documented.

## REL-07 — Legacy data transition decision

- [x] Fresh-data launch explicitly selected by the user; [decision evidence](evidence/legacy-boundary.md). No legacy import or database reset is required or authorized.

Dependencies: REL-04, PRIV-03, RACE-01, INV-01.

Deliver: Determine whether launch needs legacy data migration or a fresh start; if needed inventory/map/dry-run and reconcile records without inventing times/countries/cutoffs/ownership.

Acceptance: Data transition decision is explicit; necessary migration has tested mapping/rollback/reconciliation; legacy apps are preserved and no production data is reset.

## REL-08 — Release decision and deployment

- [ ] Complete this task and link executed evidence.

Dependencies: REL-02, REL-03, REL-05, REL-06, REL-07, DEC-01, DEC-02, DEC-03, DEC-04, DEC-05, DEC-06, DEC-07, DEC-08, DEC-09.

Deliver: Collect acceptance evidence, final legal/content review, owner sign-off, provider production configuration and deployment/backout plan.

Acceptance: All launch requirements and ST-01–31 have recorded outcomes; unresolved release-critical decisions are closed; obtain deployment authorization before production publication.

Sources: [product rules](../../../specifications/product-rules.md), [backend requirements](../../../specifications/backend/specification.md), [web requirements](../../../specifications/frontend/specification.md), [architecture plans](../architecture/README.md), [open decisions](../../../specifications/decisions-and-gaps.md).
