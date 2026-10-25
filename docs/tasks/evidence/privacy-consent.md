# PRIV-01 — Optional notification preference evidence

## Scope and contract

`ManageProfile.update` retains the authenticated self-service boundary and optimistic account version. Two optional notification channel settings are purpose-specific (`optional_notification_email`, `optional_notification_in_app`); these settings are not a declaration of legal marketing consent. Newsletter subscription, explicit race memberships and essential account/payment messages remain independent.

A new false-to-true channel change requires its explicitly configured policy version. Constructor defaults are null; null/blank versions deny a new grant before changing the name, flags or account/security version. Test versions are fixtures, not approved production policies. Withdrawal is available even without policy configuration. An unchanged flag or failed/stale update creates no new preference evidence. Name-only changes retain the existing profile audit behavior.

Existing append-only `administrative_audit` JSONB stores each changed channel separately: actor/subject account, scope, source, purpose, configured policy version (null when unavailable), previous/current flag, resulting account version, action and server timestamp. No email or display name is copied into consent evidence. UUIDs remain pseudonymous personal data. Preference and audit writes share the owning business transaction; storage failure rolls back both. The optimistic update prevents a concurrent stale snapshot from creating evidence.

No schema addition, approved version, lawful basis or retention duration was invented. This first slice does not backfill historical preferences, version race/newsletter evidence or implement erasure/export/retention. PRIV-01 remains open until those scopes and policy decisions are completed.

## Personal-data inventory from the implemented schema

| Location | Personal data / boundary | Retention or removal constraint |
| --- | --- | --- |
| `accounts`, `account_private_profiles`, `account_credentials` | UUID, password/Google identity, profile email/name/registration fields, verification and purpose-bound token hashes; private self-service/authentication | Revoke credentials on lifecycle changes; approved profile/credential retention workflow remains to implement |
| Organization memberships and eligibility imports | Account/race links, import actor/reviewer, uploaded eligibility rows and results | Organizer scope; private input and error outputs must follow approved import/proof policy |
| `bib_entitlements`, `ownership_proofs`, `private_objects`, investigation evidence | Holder eligibility/registration information, private file references and upload/submission actors | Access-controlled evidence; race plus dispute period is unresolved; deletion must also remove object bytes |
| Checkout/purchase items and ownership transitions | Buyer account, name/email and registration snapshots, holder changes | Preserve financial facts; schema permits complete one-way private snapshot redaction; open finance/disputes require justified holds |
| Seller/payment/financial operations and provider inbox | Seller/provider identifiers, payment references, protected provider payloads, authorization actors | Financial/provider retention is unresolved; provider copies require separate lifecycle handling |
| Waitlist, availability recipients, notifications | Race/account participation, explicit channel flags, recipient UUID, private notification payload/read/delivery state | Independent race preferences and current-account channel settings; inbox payloads must stay recipient-restricted |
| Newsletter subscriptions | Normalized email, independent subscription, join/quit timestamps and optional reason | Independent purpose-signed unsubscribe; newsletter retention/policy version remains unresolved |
| Outbox, mail-delivery journal, administrative audit | Event payloads, signed authorities, message/effect identifiers, actor/subject links, immutable evidence | Restrict operational access; signatures/UUIDs are not anonymization; retry and uncertainty evidence cannot be blindly deleted |
| Exports, application logs and backups | Potential copies of all applicable data; request/provider identifiers may leak through logs | Operational logs 30 days; aggregate analytics 90 days; export expiry, backup restore redaction and financial/newsletter schedules remain unimplemented/undecided |

The table is an engineering inventory, not a certified lawful-basis or compliance assessment. Actual storage adapters, provider copies and production backup policies still require operational verification.

## Observed TDD evidence

Commands run in the existing Docker runtime against per-test isolated PostgreSQL databases:

- `docker compose --env-file .env.docker exec -T -e APP_ENV=test php vendor/bin/phpunit tests/Identity/ProfileLifecycleTest.php --filter UnapprovedOptionalGrant`: red, 1 test / 3 assertions; update accepted the unconfigured optional grant. After adding the policy gate: green, 1 / 7.
- Same command with `--filter ChangedChannels`: red, 1 / 4; no purpose-specific grant evidence existed. After implementation, an intermediate JSONB ordering-only expectation was corrected to compare fields independently of JSON object order. Full lifecycle suite then green, 7 / 75.
- Withdrawal, missing-profile, stale retry, unchanged flags and mixed-channel denial tests subsequently verify the implemented contract; no separate red is claimed for already implemented behavior.
- Final focused lifecycle run: green, **9 tests / 111 assertions**, including successful retry after audit-storage failure with no partial preference/evidence writes. The coverage run immediately before that additional retry assertion was green, 9 / 107, and `ManageProfile` covered **29/29 executable lines (100%)**; no source exclusions were added.
- Targeted PHPStan level 10: clean for `ManageProfile.php` and `ProfileLifecycleTest.php`. Targeted CS Fixer: clean. Deptrac: 0 violations / 0 skipped / 0 uncovered / 0 warnings / 0 errors, 700 allowed dependencies.

Runtime composition and HTTP error handling are owned by the root integrator. Global suite, production builds and approved policy content were not validated by this slice.

## Race membership and optional race channels

PR-05 shared purchase opportunity is derived from group membership, independently of email/in-app delivery. Joining with both channels disabled remains valid. `ManageRaceWaitlist` now requires explicit configured `raceEmailPolicyVersion` / `raceInAppPolicyVersion` for each new channel grant; both default null and blank values fail closed with `UnconfiguredRaceNotificationPolicy`. Withdrawal and leaving work without configured versions. These settings do not mutate global account notification flags, newsletter subscription or essential delivery.

Each successful changed channel appends an existing audit row with authenticated caller account as actor, membership as subject, race, purpose (`race_notification_email` / `race_notification_in_app`), source, current configured policy version (or null for unconfigured withdrawal), old/new flags and timestamp. This records the configured policy at this change; no historical grant-policy linkage or approved legal text was invented. Join, preference update, leave and evidence share one transaction. The existing race row lock serializes membership state with availability capture; state is read again after acquiring that lock. A stale ended membership cannot modify or withdraw a new membership. Repeated unchanged preference updates, duplicate joins and repeated leaves create no duplicate evidence. `WaitlistStore.end` reports whether it actually ended an active membership.

Observed runs:

- `... php vendor/bin/phpunit tests/Integration/Availability/RaceWaitlistTest.php --filter MembershipWithoutAlerts`: red 1 / 4 (unapproved grant accepted), then green 1 / 8 after policy gate. Typed HTTP exception assertion added afterward.
- `... --filter RaceChannelEvidence`: red 1 / 4 (no grant audit), then green 1 / 16 after adding purpose-specific audit.
- `... php vendor/bin/phpunit tests/Integration/Availability`: green 25 / 222 before additional rollback/concurrency cases.
- Final focused `RaceWaitlistTest.php`: green 10 / 101. Includes real PostgreSQL audit trigger failure after the first grant evidence row, rollback of membership/preferences/leave, successful retries, owner restrictions, inactive/ended membership rejection, old membership leave after rejoin and two-process lock-barrier concurrency. The waiting writer observes the competing committed withdrawal after the race lock, avoiding stale or duplicate evidence. These additional cases verify already implemented behavior; no separate red is claimed.
- `... php vendor/bin/phpunit tests/Integration/Notifications/AvailabilityBatchTest.php --filter IncompleteStoredAvailability`: green 2 / 16. Real PostgreSQL permits a nullable availability event reference; batch/digest reject that incomplete persisted notification without SMTP or silently accepting delivery. No fake driver rows, disabled constraints or coverage exclusions were used. JSONB object checks and typed SQL casts make several other corrupt-row guards unreachable under the current schema; those guards remain honest uncovered protection.

Root composition/HTTP wiring owns blank production policy versions and generic French rejection responses. This slice does not change shared config/controllers, infer policy approval, retrofit historical consent, or provide newsletter policy-version evidence. PRIV-01 remains open.

Final race/availability/notification regression with coverage: `docker compose --env-file .env.docker exec -T -e APP_ENV=test -e XDEBUG_MODE=coverage php vendor/bin/phpunit tests/Integration/Availability tests/Integration/Notifications --coverage-clover var/race-consent-coverage.xml` — green **67 tests / 557 assertions**. `ManageRaceWaitlist`: **46/46 executable lines (100%)**. DBAL waitlist: 19/21, batch: 44/50, digest: 18/20; no exclusions. After removing redundant test-helper type assertions flagged by PHPStan, final race lifecycle rerun is green **10 / 81**. Targeted PHPStan level 10 and CS Fixer pass; Deptrac passes with 732 allowed dependencies, no violations/skips/uncovered/warnings/errors. Remaining adapter guards include impossible typed-row shapes and runtime port contracts not exercised by this combined snapshot; no full-project coverage result is claimed here.

## Independent newsletter purpose version

`ManageNewsletter` adds existing `AuditWriter` / `IdentityEffects` dependencies and nullable `newsletterPolicyVersion` (default null). A subscribe request checks its configured nonblank version before any email validation or persistence outcome. `UnconfiguredNewsletterPolicy` is the same refusal for new, existing, invalid and blank emails, preventing a missing-policy response from revealing subscription state. Production versions are not invented; root composition owns configuration and typed generic HTTP 503 mapping.

Changed signup/rejoin records one anonymous grant alongside the durable welcome intent. Audit actor is null, service principal `public_newsletter`; subject is the stable newsletter UUID. Metadata contains purpose `newsletter_email`, source `public_signup`, logical signed generation timestamp, configured policy version and false-to-true transition. Grant request UUID is also the audit-row ID (separate table from outbox). Repeated active signup creates neither another intent nor another audit. Rejoin records its actual monotonically advanced generation and a fresh request ID.

Purpose-signed unsubscribe works without configured policy or an app account. Only the exact current generation changing subscribed true-to-false records withdrawal evidence (`signed_unsubscribe` source, generation, configured current version or null); stale and repeated tokens add no audit. Evidence copies neither email, signed bearer token nor optional reason. Anonymous evidence records the public request; it is not a claim that submitted email ownership or an approved legal basis has been proven. Configuration records the policy supplied for the current change, not an invented link to historical acceptance.

Observed TDD and checks:

- `... php vendor/bin/phpunit tests/Integration/Newsletter/NewsletterLifecycleTest.php --filter UnconfiguredPolicy`: red 1 / 3, subscription accepted without policy; green 1 / 11 after uniform gate and typed exception.
- `... --filter AnonymousNewsletterEvidence`: red 1 / 4, no purpose/version evidence; green 1 / 21 after atomic audit writes.
- `docker compose --env-file .env.docker exec -T -e APP_ENV=test -e XDEBUG_MODE=coverage php vendor/bin/phpunit tests/Integration/Newsletter --coverage-clover var/newsletter-consent-coverage.xml`: **green 34 tests / 196 assertions**. Includes actual PostgreSQL trigger failures rolling back signup, withdrawal and rejoin generation/quit fields; successful retry of the same request/token; mail-intent failure rolling back the audit; existing/new/invalid email uniform missing-policy response; and two-process normalized-signup concurrency producing exactly one subscription, welcome intent and grant audit.
- Newsletter source coverage **91/92 executable lines (98.91%)**, Application **40/40 (100%)**, including `ManageNewsletter` 24/24. Only the schema-impossible corrupt typed newsletter-row guard remains uncovered. No source exclusions or fake DB rows.
- Targeted PHPStan level 10 passes; formatting and architecture checks run after the green suite.

This completes engineering evidence for the three distinct current settings (global optional notification channels, race-specific channels, independent newsletter). Approved version content/lawful basis, historical migration, retention durations and production operating policies remain explicit decision/work items; no compliance certification or full PRIV-01 closure is claimed.

Post-format public consent/rollback/concurrency selection: green **5 tests / 67 assertions**. Final CS Fixer dry-run clean. Deptrac: 748 allowed dependencies; 0 violations, skipped, uncovered, warnings or errors. No global suite/production build or actual external SMTP was rerun by this slice.

## Real PDO native-type protection verification (2026-10-12)

The shared isolated-test helper opens a real PostgreSQL PDO connection with `PDO::ATTR_STRINGIFY_FETCHES=true` and independently verifies `SELECT 1` returns string `'1'`. Existing Application operations reject that unsupported native-scalar mode before authorizing purchase, mutating explicit race channels, recording new notifications or projecting private inbox data. Tests preserve membership flags, audit/outbox/payment counts and read state. No driver mock, fake row, constraint change, source guard relaxation or exclusion is involved.

Observed `--filter StringifiedDriver` run: green **3 tests / 28 assertions**. These verify existing guards; no new red or implementation is claimed. Scoped AvailabilityAccess/RaceWaitlist/RecipientInbox coverage run: green **32 / 252**. Access adapter 10/10, notification preferences 7/7, waitlist 20/21, inbox adapter 27/28. Remaining guards are the ended persistence write safety check and JSON-object shape guard; schema/SQL predicates reject the latter before hydration. Newsletter selects only text/cast-to-text fields, so this native stringify mode cannot reach its corrupt-row guard; no contrived newsletter failure test was added. Targeted PHPStan level 10 and CS Fixer dry-run pass. Root owns global coverage/quality reruns.
