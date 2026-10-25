# Waitlist and recipient inbox HTTP checkpoint — 2026-10-10

Public routes `/courses/{id}/alertes` and `/notifications` use authenticated identity and Application facades. Waitlist forms join, update explicit email/in-app preferences and leave; the server derives the membership from the authenticated account, never a submitted account/membership ID. State-changing forms require CSRF tokens. Published-race checks hide draft/missing races. Unverified accounts receive a controlled denial.

Observed missing-route reds after correcting fixture setup, then PHPUnit green. `WaitlistPagesTest`: 1 test/23 assertions. `NotificationPagesTest`: 1 test/27 assertions (combined two-page initial checkpoint46 assertions, then waitlist extended to23). Inbox is recipient-scoped; forged recipient access returns404 and cannot alter another message. Unknown private payload JSON is never rendered directly. Read/empty states and private no-store responses verified. Scoped PHPStan10 passed after final added test scenarios. Complete Web directory passed17 tests/140 assertions; Twig15 templates linted. Final global checks remain pending.

AV04 remains open: listing access/deadline and stale-link journeys depend on AV02/AV03/NOTE02. Inbox currently shows category/date/read status; meaningful message rendering requires versioned payload contracts per producer.
