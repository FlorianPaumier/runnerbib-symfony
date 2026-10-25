# Seller obligations, transfers and bank status

Status: planned, 2026-10-08. Platform releases connected-account transfers; Stripe bank payouts have a distinct lifecycle.

Use the [task index and completion rules](README.md). Dependencies reference task IDs, not automatic authorization to execute another task.

## FIN-01 — Seller payable lifecycle

- [ ] Complete this task and link executed evidence.

Dependencies: SALE-01.

Deliver: Implement immutable per-item net obligation with eligibility at validation +259200 elapsed seconds and separate holds/readiness projections.

Acceptance: €100 sale net is €92.50; exact 72-hour boundaries use instants; calendar/business-day shortcuts and duplicate payables fail.

## FIN-02 — Daily Paris scheduler and claims

- [ ] Complete this task and link executed evidence.

Dependencies: FIN-01, BASE-04, DEC-08.

Deliver: Implement durable seller_release run at 05:00 Europe/Paris, missed-run visibility, per-payable claims and fencing.

Acceptance: DST/local run identity and overlapping workers are verified; a missed run preserves obligations; lease expiry alone cannot authorize repeating unknown external mutation.

## FIN-03 — Idempotent seller transfer execution

- [ ] Complete this task and link executed evidence.

Dependencies: FIN-02, PAY-01.

Deliver: Recheck readiness/funds/holds/suspensions and create/reconcile immutable transfer attempts outside DB locks.

Acceptance: One confirmed transfer/payable; uncertain network response retains original attempt; submitted transfer racing a hold is reconciled, not declared reversed (ST-16–17,20–21,29).

## FIN-04 — Next-daily retry and failure alerts

- [ ] Complete this task and link executed evidence.

Dependencies: FIN-03, NOTE-01.

Deliver: Classify actual definitive provider failure versus hold/account block/uncertainty; retry temporary failure next daily run and alert on three consecutive real failures.

Acceptance: Same-day queue redelivery creates no new attempt; skipped runs do not increment streak; success resets streak; alert deduplicates per streak (ST-18–19,30).

## FIN-05 — Bank-payout observations

- [ ] Complete this task and link executed evidence.

Dependencies: FIN-03, PAY-03.

Deliver: Reconcile provider bank status separately from platform transfer and show accurate settlement projections.

Acceptance: Pending/failed bank payout never repeats an already confirmed platform transfer; no guaranteed bank-arrival date (ST-25).

## FIN-06 — Seller funds and financial dashboards

- [ ] Complete this task and link executed evidence.

Dependencies: FIN-01, FIN-04, FIN-05, BASE-02.

Deliver: Build French eligible/waiting/held/restricted/retry/unknown/confirmed views and authorized administrator operational dashboards.

Acceptance: Transfers versus bank payouts and account-level versus payable holds remain distinct; users cannot force release or view another seller money.

## FIN-07 — Financial/provider release evidence

- [ ] Complete this task and link executed evidence.

Dependencies: FIN-02, FIN-03, FIN-04, FIN-05, SUPPORT-03.

Deliver: Run real provider test-mode and deterministic ST-16–26,29–30; exercise worker restart, claim loss and DST.

Acceptance: Record one-effect outcomes, pending exposure and verified schedules; business gate cannot pass on line coverage alone.

Sources: [product rules](../../../specifications/product-rules.md), [backend requirements](../../../specifications/backend/specification.md), [web requirements](../../../specifications/frontend/specification.md), [architecture plans](../architecture/README.md), [open decisions](../../../specifications/decisions-and-gaps.md).
