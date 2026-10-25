# Stripe checkout, authoritative payment and sales

Status: planned, 2026-10-08. Keep provider SDK models in Infrastructure and correlate every operation to durable local identity.

Use the [task index and completion rules](README.md). Dependencies reference task IDs, not automatic authorization to execute another task.

## PAY-01 — Seller connected-account onboarding

- [ ] Complete this task and link executed evidence.

Dependencies: AUTH-05, BASE-03, DEC-09.

Deliver: Implement create/reuse account, hosted onboarding links, return flow and backend-confirmed readiness reconciliation.

Acceptance: Profile/listing share one recipient-transfer account; return does not imply readiness; pending/restricted states retain draft and block applicable publication/transfer (ST-01–04).

## PAY-02 — Payment journal and hosted Checkout

- [ ] Complete this task and link executed evidence.

Dependencies: CHECK-02, PAY-01.

Deliver: Persist immutable operation/attempt identity and parameters, then create/reconcile Stripe-hosted Checkout outside DB locks.

Acceptance: Session ambiguity keeps original correlation/idempotency; full EUR total and eligible card/wallet configuration are checked; no direct seller card acceptance.

## PAY-03 — Authenticated webhook inbox

- [ ] Complete this task and link executed evidence.

Dependencies: PAY-02, BASE-03.

Deliver: Validate signature against raw request, persist authenticated deduplicated provider-scoped inbox before acknowledging receipt.

Acceptance: Bad signatures/replayed/unrelated events cannot mutate purchase; crash/redelivery and out-of-order events are repeat-safe; sensitive payload access/retention is controlled.

## SALE-01 — Whole-purchase finalization

- [ ] Complete this task and link executed evidence.

Dependencies: PAY-03, CHECK-04, INV-01, AV-01.

Deliver: Verify paid amount/currency/correlation and finalize all items, ownership transitions, sold states, waitlist removals, seller payables and outbox in one transaction.

Acceptance: No partial completion/duplicate ownership/payable; redirect does not authorize success; withdrawal/finalization conflict produces recovery rather than sale (ST-05–15,27,31).

## PAY-04 — Scheduled payment reconciliation and recovery

- [ ] Complete this task and link executed evidence.

Incremental runtime: [bounded due-checkout reconciliation](evidence/due-checkout-reconciliation.md).
The production scheduler invokes the original payment-path reconciler; collected-funds
recovery, event processing, finalization and provider acceptance remain open.

Dependencies: PAY-03, SALE-01, DEC-08.

Deliver: Scan due/missed/unknown outcomes, explicitly expire open Sessions, recover collected funds on invalid/cancelled checkout and surface stuck cases.

Acceptance: Expiry/payment races remain protected until evidenced outcome; do not replay beyond provider idempotency retention blindly (ST-09,11–13,28–29).

## SALE-02 — Purchase history, receipts and owned bibs

- [ ] Complete this task and link executed evidence.

Incremental surface: [private French checkout result page](evidence/checkout-status-web.md).
History, receipts, owned-bib integration and purchase finalization remain open.

Dependencies: SALE-01, DEC-06, BASE-02.

Deliver: Implement authorized read models, receipt delivery and French purchase-result/history/current-owned-bib views.

Acceptance: Platform confirmation is distinct from official registration; another buyer cannot access a receipt; later resale changes current ownership without rewriting history.

## PAY-05 — Provider acceptance evidence

- [ ] Complete this task and link executed evidence.

Dependencies: PAY-01, PAY-02, PAY-03, SALE-01, PAY-04.

Deliver: Execute relevant ST-01–15 and ST-27–31 in deterministic tests and actual Stripe test mode, including injected failures.

Acceptance: Record environment, event correlation and observed outcomes; provider doubles alone do not establish signature, account-capability or hosted-payment integration.

Sources: [product rules](../../../specifications/product-rules.md), [backend requirements](../../../specifications/backend/specification.md), [web requirements](../../../specifications/frontend/specification.md), [architecture plans](../architecture/README.md), [open decisions](../../../specifications/decisions-and-gaps.md).
