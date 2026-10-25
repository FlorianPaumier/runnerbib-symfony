# RGPD workflows, content and reports

Status: implementation in progress, 2026-10-13. A schema and aggregate counts do not establish compliance. Resolve category policies before enabling purges.

Use the [task index and completion rules](README.md). Dependencies reference task IDs, not automatic authorization to execute another task.

## PRIV-01 — Personal-data inventory and consent evidence

- [ ] Complete this task and link executed evidence.

Dependencies: AUTH-06, DEC-03, DEC-04.

Deliver: Map profiles/imports/snapshots/files/provider payloads/outbox/notifications/audit/exports/backups; implement current preferences and versioned purpose-specific consent/withdrawal evidence.

Acceptance: Marketing, race waitlist and essential messages remain separate; pseudonymous UUID records are not automatically treated as anonymous.

## PRIV-02 — Access/export/correction/deletion workflows

Authenticated durable intake and status authority, deletion account fencing, unsold withdrawal and protected-checkout cancellation are implemented and tested. Full export/correction/anonymization and policy acceptance remain open. See [executed evidence](evidence/privacy-requests.md).

- [ ] Complete this task and link executed evidence.

Dependencies: PRIV-01, CHECK-04, SUPPORT-02.

Deliver: Implement authenticated requests, auditable progress/outcomes and coordinated account anonymization with immediate unsold-offer removal.

Acceptance: Credentials revoked; private data cannot escape through another session/export; open payments/disputes preserve only justified supporting data; financial facts remain intact.

## PRIV-03 — Retention and backup restoration handling

- [ ] Complete this task and link executed evidence.

Dependencies: PRIV-02, BASE-05, DEC-04.

Deliver: Implement purge scheduling, open-case hold checks/reviews, private object/export cleanup, payload redaction and restoration procedures honoring completed deletions.

Acceptance: Logs expire after 30 days and aggregate analytics after 90; evidence/financial/newsletter policies are accepted; private snapshot redaction cannot rewrite amounts.

## REPORT-01 — Launch aggregate analytics

- [ ] Complete this task and link executed evidence.

Dependencies: SALE-01, SELL-03, AUTH-02, BASE-03.

Deliver: Count committed signups/listing publications/completed purchases using deduplicated effects and agreed retention.

Acceptance: No emails/proofs/payment identifiers/per-user tracking; retries do not double count; optional third-party tracking stays deferred. Propose a daily_metrics table only if persistence needs justify it.

## REPORT-02 — Operational financial and organizer reports

- [ ] Complete this task and link executed evidence.

Dependencies: FIN-06, ORG-03, SUPPORT-03.

Deliver: Define gross/net/refund/transfer/balance semantics and implement authorized read views/exports at the correct fact grain.

Acceptance: Multi-item purchases and repeated attempts do not multiply amounts; pending/failed operations are not confirmed money; access and small-group disclosure controls are tested.

## NEWS-01 — Independent newsletter lifecycle

- [ ] Complete this task and link executed evidence.

Dependencies: BASE-05, BASE-02, DEC-04.

Deliver: Implement unique normalized-email signup, purpose-specific one-click unsubscribe and rejoin resetting quit timestamp/reason.

Acceptance: No account/waitlist created; unsubscribe reason optional; repeated signup creates no duplicate; French public screens and delivery work.

## CONTENT-01 — Testimonies and public content

- [ ] Complete this task and link executed evidence.

Dependencies: AUTH-05, DEC-07, BASE-02.

Deliver: Implement accepted submission/moderation/publication and French help/contact/legal/privacy pages.

Acceptance: Unapproved content stays private; moderation evidence exists; content accurately distinguishes platform sale, race registration and bank arrival.

## PRIV-04 — Privacy/preferences/reporting screens

Authenticated French request intake and public body-only signed status-code submission are implemented. [Executed web evidence](evidence/privacy-web.md) covers suspended-account follow-up and fail-closed configuration. Export/correction completion, final deletion, retention decisions and reports remain open.

- [ ] Complete this task and link executed evidence.

Dependencies: BASE-02, PRIV-02, PRIV-03, REPORT-02, NEWS-01.

Deliver: Build French request/export/delete/preferences and authorized report screens with clear pending exceptions.

Acceptance: Account deletion is understandable without exposing internal details; authorization, keyboard navigation and essential-versus-optional choices remain correct.

Sources: [product rules](../../../specifications/product-rules.md), [backend requirements](../../../specifications/backend/specification.md), [web requirements](../../../specifications/frontend/specification.md), [architecture plans](../architecture/README.md), [open decisions](../../../specifications/decisions-and-gaps.md).
