# Shared race waitlists and notifications

Status: planned, 2026-10-08. One-hour group access is independent of each buyer checkout protection. No individual reservation is created by an alert.

Use the [task index and completion rules](README.md). Dependencies reference task IDs, not automatic authorization to execute another task.

## AV-01 — Race waitlist membership

- [ ] Complete this task and link executed evidence.

Dependencies: AUTH-05, RACE-01.

Deliver: Implement authorized join/leave and explicit per-race channel preferences with race-row coordination.

Acceptance: One active membership/account/race; cross-account changes denied; newsletter never changes membership.

## AV-02 — Immutable availability snapshots

- [ ] Complete this task and link executed evidence.

Dependencies: AV-01, SELL-03, BASE-03.

Deliver: On publication/repricing create versioned offer and snapshot members under shared race lock; invalidate superseded/withdrawn offers.

Acceptance: Membership/publication competition yields a deterministic boundary; late joiners excluded; price/version/deadline and recipient count agree.

## AV-03 — Authoritative access/deadline policy

- [ ] Complete this task and link executed evidence.

Dependencies: AV-02.

Deliver: Implement access queries using persisted instant plus 3600 seconds and snapshot membership.

Acceptance: Empty snapshot is immediately public; all can view priority/deadline; expiry is public without cron; notification delay cannot extend or restart a window.

## NOTE-01 — Recipient inbox and preferences

- [ ] Complete this task and link executed evidence.

Dependencies: AUTH-05, BASE-03.

Deliver: Implement deduplicated notification records, read state, per-channel outcomes and essential-versus-optional preferences.

Acceptance: A user cannot read/change another inbox; optional marketing can be disabled without suppressing essential account/payment messages.

## NOTE-02 — Five-minute availability batches

- [ ] Complete this task and link executed evidence.

Dependencies: AV-03, NOTE-01, BASE-04, BASE-05.

Deliver: Implement persisted batch scheduling, outbox delivery and deadline-aware retries with sanitized errors.

Acceptance: Redelivery sends no duplicate effect; stale messages never grant eligibility; original deadline remains visible and delivery failure does not extend it.

## AV-04 — Waitlist and notification screens

- [ ] Complete this task and link executed evidence.

Dependencies: BASE-02, AV-03, NOTE-01, NOTE-02.

Deliver: Build French join/leave/preferences, inbox and listing access/deadline states.

Acceptance: Late member, outsider, stale-link and expired-offer journeys reflect backend authorization; timestamps distinguish priority access from checkout lock.

Sources: [product rules](../../../specifications/product-rules.md), [backend requirements](../../../specifications/backend/specification.md), [web requirements](../../../specifications/frontend/specification.md), [architecture plans](../architecture/README.md), [open decisions](../../../specifications/decisions-and-gaps.md).
