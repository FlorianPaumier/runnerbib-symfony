# Investigations, refunds, recovery and organizer handoff

Status: planned, 2026-10-08. Required before real-money launch; these capabilities are not optional simply because table count was reduced.

Use the [task index and completion rules](README.md). Dependencies reference task IDs, not automatic authorization to execute another task.

## SUPPORT-01 — Issue reporting and evidence

- [ ] Complete this task and link executed evidence.

Dependencies: SALE-01, AUTH-05, BASE-05.

Deliver: Implement authorized reports, private evidence, discovery/report timestamps, acknowledgment and investigation history.

Acceptance: 48-hour reporting/two-business-day acknowledgment policy is represented correctly; evidence/resource access is scoped; retention subjects/reviews are recorded.

## SUPPORT-02 — Pre-transfer holds and post-transfer suspension

- [ ] Complete this task and link executed evidence.

Dependencies: SUPPORT-01, FIN-01, FIN-03.

Deliver: Place independent payable holds, suspend affected seller remaining transfers after paid exposure and coordinate with running operations.

Acceptance: All relevant independent holds remain effective; only super-admin lifts seller suspension with reason/audit; hold does not reverse executed money (ST-21–23).

## SUPPORT-03 — Policy refund and technical correction

- [ ] Complete this task and link executed evidence.

Dependencies: SUPPORT-02, PAY-04, DEC-05, DEC-07.

Deliver: Implement scoped super-admin approvals, immutable refund allocations/caps and separate automatic duplicate-charge correction authority.

Acceptance: Ordinary admins denied; accepted fee intent remains separate from technical correction; monetary success and ownership consequences require explicit evidence/policy.

## SUPPORT-04 — Authorized recovery of transferred exposure

- [ ] Complete this task and link executed evidence.

Dependencies: SUPPORT-02, SUPPORT-03, DEC-05.

Deliver: Implement reason/amount/audit authorization, permitted recovery adapter, confirmed allocations and outstanding receivables.

Acceptance: Unknown/failed recovery remains outstanding; no automatic bank debit, blind duplicate recovery or unapproved future-payable offset (ST-24).

## ORG-03 — Organizer CSV exports and registration follow-up

- [ ] Complete this task and link executed evidence.

Dependencies: ORG-02, SALE-01, DEC-02, DEC-06.

Deliver: Implement scoped on-demand exports and supported platform-controlled official updates with versioned requests/evidence.

Acceptance: Previous/new holder and transfer timestamp are correct; private fields available only to authorized organizer; CSV injection/temporary-file risks handled; applied requires evidence.

## SUPPORT-05 — Moderation and financial administration screens

- [ ] Complete this task and link executed evidence.

Dependencies: BASE-02, SUPPORT-01, SUPPORT-02, SUPPORT-03, SUPPORT-04, ORG-03.

Deliver: Build French case/evidence/approval/hold/suspension/recovery/registration dashboards with clear pending/failed/confirmed outcomes.

Acceptance: Server permissions and audit work despite altered requests; rejected actions leave no provider side effect; seller hold release does not clear independent investigations.

Sources: [product rules](../../../specifications/product-rules.md), [backend requirements](../../../specifications/backend/specification.md), [web requirements](../../../specifications/frontend/specification.md), [architecture plans](../architecture/README.md), [open decisions](../../../specifications/decisions-and-gaps.md).
