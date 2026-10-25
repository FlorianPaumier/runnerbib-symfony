# Atomic checkout authorization and cancellation

Status: planned, 2026-10-08. Implement provider-neutral application behavior first; provider calls follow committed local intent.

Use the [task index and completion rules](README.md). Dependencies reference task IDs, not automatic authorization to execute another task.

## CART-01 — Buyer cart and registration input

- [ ] Complete this task and link executed evidence.

Dependencies: AUTH-05, RACE-04, SELL-01, AV-03.

Deliver: Implement buyer-owned cart items, multi-race/multi-seller validation and versioned required fields.

Acceptance: One item/race; own/unavailable/duplicate offers are rejected; cart grants no reservation or price guarantee; totals sum per-item quotes.

## CHECK-01 — Atomic authorization and durable protection

- [ ] Complete this task and link executed evidence.

Dependencies: CART-01, BASE-03, INV-01.

Deliver: Implement shared lock order, buyer/race guards, all-item protection and committed checkout snapshots.

Acceptance: Two buyers/same bib and one buyer/two bibs same race cannot both acquire eligible protection; one invalid item rolls back every lock/snapshot/intent.

## CHECK-02 — Explicit transfer acknowledgment

- [ ] Complete this task and link executed evidence.

Dependencies: CHECK-01, DEC-06.

Deliver: Record initially unchecked per-nonparticipating-race acknowledgment text/version/time, registration schema/answers and immutable money snapshots.

Acceptance: Missing acknowledgment blocks authorization; identity/profile/race price edits cannot mutate committed terms; snapshots preserve exact fee-policy version.

## CHECK-03 — Processing, expiry and reconciliation policy

- [ ] Complete this task and link executed evidence.

Dependencies: CHECK-01, DEC-08.

Deliver: Implement separate initial 900-second deadline, processing/unknown protection and safe release through provider status port.

Acceptance: Crossing the shared hour does not invalidate an authorized checkout; timeout alone never releases processing/unknown payment; terminal protection never reactivates.

## CHECK-04 — Withdrawal and whole-checkout cancellation

- [ ] Complete this task and link executed evidence.

Dependencies: CHECK-03, SELL-03.

Deliver: Implement seller withdrawal/cancellation request with serialized completion conflict and funds-recovery intent.

Acceptance: One withdrawn item cancels the entire checkout; sellers cannot force-unlock or withdraw after completion; collected funds require confirmed recovery.

## CHECK-05 — French cart and checkout screens

- [ ] Complete this task and link executed evidence.

Dependencies: BASE-02, CART-01, CHECK-02, CHECK-03.

Deliver: Build itemized fees, editable registration, per-race acknowledgments, failing-item messages and pending/cancelled/recovery states.

Acceptance: No silent item removal/partial purchase; displayed amounts/deadlines come from backend; no browser-controlled successful purchase state.

Sources: [product rules](../../../specifications/product-rules.md), [backend requirements](../../../specifications/backend/specification.md), [web requirements](../../../specifications/frontend/specification.md), [architecture plans](../architecture/README.md), [open decisions](../../../specifications/decisions-and-gaps.md).
