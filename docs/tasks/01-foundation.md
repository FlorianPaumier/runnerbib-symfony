# Runtime, persistence and web foundation

Status: in progress, 2026-10-08. Existing bootstrap and schema files are complete. These tasks finish runtime capabilities needed by the marketplace.

Use the [task index and completion rules](README.md). Dependencies reference task IDs, not automatic authorization to execute another task.

## BASE-01 — Apply and verify development schema

- [x] Complete this task — [executed evidence](evidence/base-01.md).

Dependencies: None.

Deliver: Use migration status/dry-run, then apply the existing version to the intended development database; keep all other application databases intact.

Acceptance: 40 marketplace tables exist, migration status is current and readiness passes; record commands; never reset a volume to migrate.

## BASE-02 — French web and asset foundation

- [ ] Complete this task and link executed evidence.

Dependencies: DEC-09.

Deliver: Install/configure Twig, selected Symfony UX packages and Tailwind; build responsive shared layout, navigation, forms and error states.

Acceptance: Development and production assets render real Symfony pages; French locale and accessible form/error patterns work.

## BASE-03 — Durable messaging primitives

- [ ] Complete this task and link executed evidence.

Dependencies: BASE-01.

Deliver: Implement Application transaction/outbox/audit ports, DBAL adapters and repeat-safe outbox dispatch/consumption through Symfony Messenger.

Acceptance: Crash between commit and dispatch is recoverable; redelivery produces one database effect; transactions do not span provider network calls.

## BASE-04 — Cache, sessions and queue configuration

- [ ] Complete this task and link executed evidence.

Dependencies: DEC-09, BASE-03.

Deliver: Configure Redis for disposable cache/session support as selected, and the Cloud-managed queue adapter with local development transport.

Acceptance: Redis loss cannot lose ownership, financial intent or checkout protection; worker retry/failure/shutdown behavior is documented and smoke-tested.

## BASE-05 — Private storage and delivery adapters

- [ ] Complete this task and link executed evidence.

Dependencies: DEC-03, DEC-09.

Deliver: Implement private object-storage and mail adapters, authorized temporary downloads and cleanup; use local fixtures/Mailpit without public proof URLs.

Acceptance: Unauthorized file access is rejected server-side; failed uploads/downloads/deliveries have controlled outcomes; secrets/proofs never enter logs.

## BASE-06 — Production roles and deployment skeleton

- [ ] Complete this task and link executed evidence.

Dependencies: DEC-09, BASE-01.

Deliver: Provision deployment-owned migration/runtime roles and secrets, apply reviewed runtime grants, and configure staging build/start/worker/scheduler commands.

Acceptance: Runtime cannot perform DDL or write migration bookkeeping; production cache clear precedes migration execution; Cloud database/Redis/queue/storage smoke checks pass.

Sources: [product rules](../../../specifications/product-rules.md), [backend requirements](../../../specifications/backend/specification.md), [web requirements](../../../specifications/frontend/specification.md), [architecture plans](../architecture/README.md), [open decisions](../../../specifications/decisions-and-gaps.md).
