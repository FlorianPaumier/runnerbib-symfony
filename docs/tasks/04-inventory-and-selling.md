# Eligibility, ownership and verified listings

Status: planned, 2026-10-08. Persistence tables are infrastructure; claiming, moderation and selling are unimplemented business behavior.

Use the [task index and completion rules](README.md). Dependencies reference task IDs, not automatic authorization to execute another task.

## SELL-01 — Exact fee policy

- [x] Complete this task: [executed fee-policy evidence](evidence/fees.md).

Dependencies: None.

Deliver: Implement integer-cent EUR fee quote with checked arithmetic, per-bib minima, half-up rounding and positive seller net.

Acceptance: At €100 buyer pays €105 and seller net is €92.50; at €200 buyer pays €210 and seller net is €185; test half-cent, minima, invalid/overflow boundaries.

## INV-01 — Entitlement identity and ownership history

- [ ] Complete this task and link executed evidence.

Dependencies: RACE-01, AUTH-01.

Deliver: Implement race-local textual bib identity, original-holder private fields, verified claiming and append-only transitions.

Acceptance: Leading zeros persist; imports do not fabricate accounts; one current owner/race and one entitlement/race/bib survive concurrent claims.

## INV-02 — Organizer eligibility imports

- [ ] Complete this task and link executed evidence.

Dependencies: ORG-02, INV-01, DEC-02, BASE-05.

Deliver: Implement preview, default skip-invalid/duplicate behavior, explicit allowlisted update mode and immutable row results.

Acceptance: Duplicate rows leave existing records unchanged by default; invalid rows are reported; update mode never alters ownership/payment or protected listed/purchased fields.

## SELL-02 — Ownership proof submission and review

- [ ] Complete this task and link executed evidence.

Dependencies: INV-01, AUTH-05, DEC-03, BASE-05.

Deliver: Implement participating import/email proof and nonparticipating original purchase-document moderation, with exact version audit.

Acceptance: Wrong holder and email-only nonparticipating proof fail; reviewers see private evidence only when authorized; changes require revalidation.

## SELL-03 — Listing lifecycle and publication gates

- [ ] Complete this task and link executed evidence.

Dependencies: SELL-01, SELL-02, PAY-01, RACE-04, BASE-03.

Deliver: Implement draft, publication, price edits and withdrawal using shared transaction/lock ordering.

Acceptance: Only current owner with approved proof and provider-confirmed seller readiness publishes; competition cannot create two live listings; locked-item edit rules hold.

## SELL-04 — Relisting after purchase

- [ ] Complete this task and link executed evidence.

Dependencies: SALE-01, SELL-03.

Deliver: Allow current owner to create a new listing for the existing entitlement and preserve all previous sales.

Acceptance: Old seller/listing cannot reactivate; resale permits later replacement purchase in that race; refund consequences follow DEC-05 rather than inference.

## SELL-05 — Selling, proof and import screens

French inventory draft/proof attachment/publication/repricing/withdrawal controls are implemented. [Executed web evidence](evidence/selling-web.md) includes a protected-entitlement replacement-listing regression. Proof moderation, transfer-policy acceptance and remaining import/review journeys keep the task open.

- [ ] Complete this task and link executed evidence.

Dependencies: BASE-02, INV-02, SELL-02, SELL-03.

Deliver: Build French draft/proof/review/publication/import screens, rejection reasons, fee/net preview and moderated public listing views.

Acceptance: Draft survives onboarding/review; seller sees fees/net and rights before validation; proof approval does not imply approval of transfer protocol.

Sources: [product rules](../../../specifications/product-rules.md), [backend requirements](../../../specifications/backend/specification.md), [web requirements](../../../specifications/frontend/specification.md), [architecture plans](../architecture/README.md), [open decisions](../../../specifications/decisions-and-gaps.md).
