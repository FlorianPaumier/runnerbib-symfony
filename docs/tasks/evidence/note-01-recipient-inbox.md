# NOTE-01 — Private recipient inbox and independent preferences

Implemented locally on 2026-10-09. No commit, external provider send or production rollout.

`RecipientInbox` is an Application facade with Application DTOs and inward-owned ports. Creation is an internal capability: account/payment records are essential; marketing records use independent optional email/in-app preferences. Availability has a distinct explicit-channel method so a race opt-in is never silently disabled by global defaults. Only the authenticated account ID belongs on public `messages`/`read` calls; Presentation must never expose internal record/outcome operations or trust a request-provided account ID.

Records deduplicate on recipient/event key without replacing their payload, channels, read instant or outcome on replay. Inbox rows filter by the recipient and accepted in-app channel. Reading another account's message fails generically, including for administrators. Read state is repeat-safe and keeps its first instant. Pagination has a limit of 1–100 and a `(created_at,id)` descending cursor, avoiding missing rows that share an instant. Channel outcomes update one channel atomically; accepted/skipped outcomes cannot regress. Accepted email metadata means provider/transport acceptance, not proof of recipient delivery. NOTE-02 delivery/batching remains separate.

`Version20261009000100` adds only `accounts.optional_in_app_enabled boolean NOT NULL DEFAULT false`. Existing email and explicit race subscriptions remain separate. Existing records default to no optional in-app consent. Identity owns the profile form/store integration.

Observed TDD:

- `docker compose --env-file .env.docker exec -T -e APP_ENV=test php vendor/bin/phpunit tests/Integration/Notifications`: red missing `RecipientInbox`; green 1 test / 8 assertions for essential dedup/private inbox.
- `--filter Independent`: red SQLSTATE 42703 missing optional-in-app column; forward migration and preference port produced green 2 / 15.
- `--filter ReadState`: red missing `read`; implemented recipient-only first-read state and channel outcomes; green 3 / 21.
- `--filter ExplicitRace`: red missing `recordAvailability`; implemented explicit channels; green 5 / 30 including migration rollback/replay.
- `--filter UnknownCategories`: red accepting an unknown category; sanitized category/event-key/object-JSON validation gave green 6 / 38.
- `--filter Bounded`: red returning two rows when limit was one; cursor/limit implementation gave green 7 / 45.
- Negative channel/page bounds, terminal acceptance, anonymized recipient and malformed stored channel outcomes expanded to green 10 / 59. A subsequent syntax error in an additional malformed-state fixture was corrected before this green run; it was not a behavioral red.

The isolated migration commands execute this exact forward migration down and up on a fresh random test database, verify column absence after down, default false on replay, and preserve the existing email flag. Only isolated test databases are dropped. Development status then reported database `app`, current Version20261008000300 and exactly one pending migration. `doctrine:migrations:migrate --dry-run --no-interaction` reported one migration/three queries; the actual migrate reported one migration/one SQL query. No volume reset or other database/role mutation.

Quality: project CS Fixer applied, scoped PHPStan 10 clean, global Deptrac zero violations/warnings/errors. Combined waitlist/inbox Xdebug coverage run: 15 tests/93 assertions, core 61/61 (100%), source 113/117 (96.58%). Four uncovered lines are defensive typed-schema shape guards; no coverage exclusions or fabricated fault tests were added. Final regression commands/results follow below. The parent agent runs the complete project coverage/quality gates.

Unfinished surrounding tasks: scheduled availability batches and provider delivery (NOTE-02), final French screens (AV-04), privacy retention/export/redaction and Cloud environment evidence. No claim of those completions is made here.

Final regression: `docker compose --env-file .env.docker exec -T -e APP_ENV=test php vendor/bin/phpunit --filter 'RaceWaitlistTest|RecipientInboxTest'` passed **16 tests / 102 assertions**. Scoped PHPStan passed after the final test typing fixes. Global Deptrac passed **0 violations, 0 warnings, 0 errors**.

## NOTE02 initial scheduler slice — 2026-10-10

`APP_ENV=test` is required explicitly with the current development container environment. The initial invocation without it failed test setup (`test.service_container` missing), and was not a business red result.

- Existing persisted scheduling test verified green: 1 test / 12 assertions.
- Due-batch public facade red: `RunAvailabilityBatch` missing. Green after implementation: 2 tests / 22 assertions.
- Bounded processing red: expected 100 accepted notifications, observed 101. Green after limiting each transaction to 100 and retaining pending batch progress: 4 tests / 38 assertions.
- `docker compose --env-file .env.docker exec -T -e APP_ENV=test php vendor/bin/phpunit tests/Integration/Notifications tests/Integration/Availability`: 36 tests / 273 assertions before the added bounded test.
- Scoped PHPStan level 10 for Application/Infrastructure Notifications and notification integration tests: no errors.

The scheduler retains original deadlines, rechecks account/membership preferences and authoritative availability, writes durable per-recipient digest intents, and resumes bounded chunks. SMTP digest handling, concurrent scheduler proof (race-before-run lock order corrected by review), delivery revalidation, and complete NOTE02 coverage remain outstanding. No task marked complete and no external mail sent.

## NOTE02 delivery and CLI slices — 2026-10-10

- Withdrawn digest red: missing `DeliverAvailabilityDigest`; green 1 test / 8 assertions through real PostgreSQL and the external mail port.
- Valid digest red: public delivery returned false; green after stable Message-ID and durable journal claim/send/accept.
- Ambiguous delivery red: private transport exception escaped; green after recording unknown and raising sanitized `UncertainMailDelivery`. Repeat attempts do not call SMTP again.
- Recipient outcome red: email remained pending after accepted transport; green after recording accepted channel state.
- Actual Symfony SMTP adapter red: class missing; green with real local Mailpit, trusted `/dossards/{id}` URL, French price and original per-offer deadline.
- Integer-limit SMTP red: float formatting produced `92 233 720 368 547 760,00` for `9223372036854775807` cents. Exact integer quotient/remainder formatting green: 2 tests / 39 assertions.
- Outbox handler red: missing `AvailabilityMessageHandler`; green 1 test / 8 assertions for actual persisted scheduling/dedup. Handler validates event version, UUIDs and bounded digest notification IDs.
- `app:availability:run` red: missing command namespace; green through the configured CommandTester and real isolated PostgreSQL. Bounded chunks preserve future batches. Outage red: raw Doctrine exception; green sanitized output with rollback retaining pending work. Initial CLI 2 tests / 13 assertions, option-boundary coverage added subsequently.
- Concurrent worker proof green: child owns race lock and waits for parent to block before processing; parent then declines completed run, one digest only (1 test / 9 assertions). This confirms reviewed race-before-run lock order.
- Notifications + Availability suite before final added guards: 56 tests / 381 assertions green.

Local execution: `docker compose --env-file .env.docker exec -T php bin/console app:availability:run --limit=100` processes due batch chunks. Each chunk contains at most 100 notifications. Then dispatch committed intents with `app:outbox:dispatch`, and consume the configured Messenger async queue. Actual hosted scheduler/worker configuration remains an operator release requirement.

The existing effect-key SMTP journal is reused; no new notification delivery table. Outbox handlers and mail composition are wired by the root agent. SMTP acceptance means the transport accepted a message, not guaranteed mailbox receipt. All transport exceptions are conservatively ambiguous and require reconciliation; differentiated definitive-failure retry policy is not implemented in this slice. No NOTE02 task completion checkbox has been changed.

Final focused Xdebug run: 42 tests / 299 assertions passed. All newly implemented Application notification use cases have 100% line coverage; root-owned NotificationView projection requires its HTTP tests. CLI 17/17 lines, outbox handler 24/24, SMTP adapter 7/7. Typed defensive DB guards remain honestly uncovered. PHPStan scoped level 10 clean; formatting applied. Runtime command smoke: `Processed batch chunks: 0`.

Final eligibility review found a stale waitlist member could own another bib for the same race and still receive an alert. Public real-PG tests observed two red failures (in-app accepted, SMTP sent); using existing `canInitiatePurchase` guard in batch and delivery turned both green (2 tests / 14 assertions). This reuses the authoritative ownership and buyer/race checkout guards.
