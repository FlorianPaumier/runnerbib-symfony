# Finish the Runnerbib rebuild and close the audit findings

Updated: 2026-10-19. Scope: the accepted French Symfony 8.1 marketplace launch, plus security fixes for legacy applications that remain deployed.

This is the ordered execution guide. The [86-task backlog](docs/tasks/README.md) retains the detailed requirements and task IDs; this file does not replace it. Use the [product rules](../specifications/product-rules.md), [glossary](../specifications/glossary.md) and [decision register](../specifications/decisions-and-gaps.md) as the source of truth. The user's Symfony 8.1 choice overrides older framework-version references.

## Starting point

- The rebuild already has Docker images, migrations, identity/organization foundations, race discovery, selling rules, availability, durable messaging, checkout protection, hosted payment submission, authenticated webhook storage and several French screens.
- Webhook receipt is not purchase completion. Collected-funds recovery, ownership finalization, seller transfers, support, privacy operations and several screens remain unfinished.
- Latest stable checkpoint: **770 tests / 4,774 assertions passed**. Coverage run: **770 tests / 4,789 assertions**, **98.05% overall (2,771/2,826)** and **100% Domain/Application (954/954)**; both coverage gates pass. See [checkout status evidence](docs/tasks/evidence/checkout-status-web.md), [design verification](DESIGN.md), [selling web evidence](docs/tasks/evidence/selling-web.md) and [privacy web evidence](docs/tasks/evidence/privacy-web.md). Global mutation and release acceptance remain open.
- Static quality checks and both Docker builds passed at that checkpoint. The isolated production smoke stack migrated through `Version20261011000100`, passed public-page/readiness checks and was stopped with volumes preserved. This is local evidence, not production acceptance.
- Global mutation acceptance, actual Stripe test-mode acceptance, Cloud operation and restoration rehearsal remain unverified.
- Implementation after the three original authorized commits remains uncommitted. Do not treat a partial implementation or an assigned task as completed acceptance.

## Workflow for every implementation step

1. Read the applicable specification, project instructions and callers, including tests, fixtures, service wiring, messages and templates.
2. Choose one observable behavior and its public interface. Write one meaningful failing test; confirm the failure is missing behavior rather than broken setup.
3. Implement the smallest passing change through Presentation → Application → Domain, with Infrastructure implementing inward-owned ports.
4. Run the focused test. Refactor with the suite green; then run the relevant regression suite and `make test`.
5. Include invalid input, permissions, retries, time boundaries and competing transactions where the behavior needs them. Use real PostgreSQL/filesystem infrastructure and controlled external-provider boundaries.
6. Record exact commands, observed red/green results and limitations under `docs/tasks/evidence/`. Verification of existing behavior may start green; never invent a red run.
7. Mark the original task complete only after its acceptance and required quality gates pass. Preserve release gates for unapproved policies or unavailable provider environments.

Reuse existing tables, ports and installed dependencies. Add a table or abstraction only for a demonstrated missing behavior. Keep mobile, chat and English outside this launch; do not rebuild legacy applications unnecessarily.

## Step 1 — Establish a stable baseline and legacy boundary

- [ ] Review current Git changes and interrupted agent work. Identify complete slices, partial files, active commands and unverified changes before resuming edits.
- [x] Confirm which legacy applications remain deployed or supported: on 2026-10-12 the user answered **None** for `api-bib`, `web-app` and `bib-waitlist`. Keep their source as reference; see [legacy boundary](docs/tasks/evidence/legacy-boundary.md).
- [ ] For retired applications, plan removal of public routes/deployments instead of rebuilding their features. Preserve required data and transition evidence.
- [ ] For retained applications, execute Step 2 before exposing financial operations.
- [ ] Keep one integrator responsible for shared configuration and one agent per write-sensitive module. Freeze source during combined verification runs to avoid measuring files that change mid-run.

Done when: the current source baseline and deployment boundary are recorded, with no accidental changes to independent applications.

## Step 2 — Close the legacy audit defects where those applications remain active

Audit numbers below match the preceding audit report.

**Not applicable to this launch:** the user confirmed that none of these legacy applications remain deployed or supported. Keep the following remediation list as a record of the audit, not work to execute against archived applications. If a legacy application is brought back, reassess these gates first. This decision does not authorize deletion or settle whether retained legacy data should be imported.

- [ ] **Audit 1:** Disable the legacy Stripe webhook, or verify the signature over the raw body before parsing. Test forged/missing/expired signatures, duplicate events and absence of unauthorized seller-role/account changes.
- [ ] **Audit 2:** Disable unused refund/invoice routes, or enforce super-admin authority, local transaction scope, validated amounts and durable operation identity before provider calls. Test ordinary users/admins, arbitrary provider IDs and retries.
- [ ] **Audit 3:** Scope payment-detail reads to the caller's local transaction. Return an explicit safe projection; test that another user's payment cannot be read.
- [ ] **Audit 4:** Remove inherited database/Redis/public web bindings from legacy production Compose using an explicit reset or standalone configuration. Inspect the resolved Compose ports; an empty override list is insufficient. Build and smoke-test the result.
- [ ] **Audit 7:** Repair the Next.js social-login cookie contract. A stored account ID is not the user object expected by profile/role/upload readers. Load trusted identity from the authenticated backend and test the complete callback-to-profile flow; do not make client-supplied role data authoritative.
- [ ] **Audit 11:** Change the Laravel PostgreSQL container port mapping from `54322` to `5432`. Validate Compose and prove a host client can reach the database.
- [ ] **Audit 12:** Recheck all callers of the unused legacy `processSellerTransfers` method and remove it if it remains unused. Do not connect an unjournaled transfer loop to the new payment workflow.

Done when: retained endpoints have authorization and regression evidence, or retirement has removed their public exposure. Do not call a source fix a verified live-provider integration.

## Step 3 — Restrict production infrastructure and make background work operational

Tasks: BASE-04–06, REL-04, REL-06; audits 5 and 8. See [foundation](docs/tasks/01-foundation.md).

- [x] Separate PostgreSQL bootstrap/schema-owner credentials from PHP runtime credentials. Update production `DATABASE_URL` wiring without committing credentials.
- [ ] Apply and verify `tools/database-runtime-grants.sql` against a dedicated runtime role. Prove it cannot own/alter schema, create databases/roles or bypass protections, while legitimate application operations still work.
- [ ] Provision supervised web/queue workers and the required scheduled commands in the selected deployment environment. Record commands, resource limits, shutdown behavior and restart handling.
- [ ] Verify outbox dispatch, queue consumption, failed-message visibility and due availability batches through actual running processes.
- [ ] Verify Redis loss only affects disposable cache/session state, not ownership, payment protection or durable intents.
- [ ] Confirm private storage remains outside the web root, persists across restart and has restricted permissions. Include it in the backup/retention plan.
- [ ] Validate both Compose files, build development/production images and repeat isolated runtime smoke checks after infrastructure changes.

Done when: the runtime uses restricted credentials and required background work actually runs. A healthy HTTP endpoint alone is insufficient.

## Step 4 — Resolve policies when their dependent behavior starts

Tasks: DEC-01–09. See [decision tasks](docs/tasks/00-decisions.md).

- [ ] DEC-01: agree the missing-resale-cutoff outcome; preserve known stored deadlines.
- [ ] DEC-02: agree versioned registration fields, CSV headers/encoding/byte and row limits, and the update allowlist.
- [ ] DEC-03: agree proof types/size, scanning, authenticity review, redaction and dispute-related retention.
- [ ] DEC-04: agree financial/newsletter/private-object/export/audit retention and open-case exceptions.
- [ ] DEC-05: agree refund ownership/resale consequences and permitted recovery mechanisms. A financial refund must not silently restore ownership.
- [ ] DEC-06: agree organizer integration/export fields and receipt/official-registration wording.
- [ ] DEC-07: approve testimony moderation and help/contact/legal/privacy/CGV content.
- [ ] DEC-08: agree operational escalation, query/load budgets and accessibility acceptance criteria.
- [ ] DEC-09: supply the actual Stripe/Google/Cloud configuration and credential ownership through secret management.
- [ ] Record accepted notification/newsletter policy versions and configure them explicitly. Blank configuration must continue refusing new grants without blocking valid withdrawals.

Done when: accepted decisions are in the authoritative register and dependent behavior has literal valid/invalid examples. Continue unrelated work while decisions remain open; never turn elapsed time into approval.

## Step 5 — Complete identity, organization and race management

Tasks: AUTH-01–06, ORG-01–02, RACE-01–06. See [identity](docs/tasks/03-identity-and-organizations.md) and [races](docs/tasks/02-races-and-discovery.md).

- [ ] Review existing registration, verification, recovery, role and session-revocation evidence; finish missing acceptance rather than rewriting working slices.
- [ ] Complete Google identity linking with verified-provider identity and account-linking safeguards; verify the real configured provider separately.
- [ ] Finish organization approval, owner/manager management and scoped race creation/editing screens.
- [ ] Wire accepted registration and resale policies into race management. Preserve versioned changes and deny cross-organization writes.
- [ ] Measure public discovery with representative legal data and real PostgreSQL plans. Verify pagination, stable ordering and accepted latency budgets.
- [ ] Complete French account/public-profile/race screens and authorization/error cases.

Done when: authorized users can manage their organization/races and discover courses through complete French flows, with no private profile leakage.

## Step 6 — Complete eligibility, proof and seller workflows

Tasks: INV-01–02, SELL-01–05, PAY-01. See [inventory/selling](docs/tasks/04-inventory-and-selling.md).

- [ ] Finish verified entitlement claiming and append-only ownership history. Keep textual bib numbers, including leading zeros; imports must not fabricate accounts.
- [x] Finish the French organizer CSV screen using existing scoped preview/import use cases. Check authority before private preview or source reading; bound reads by configured limits.
- [x] Keep preview and commit separate, reparse on execution and reuse the import identity for retries. Do not copy raw CSV into flash/logs/session storage unnecessarily.
- [ ] Verify default skip-invalid/duplicate behavior, allowlisted updates, immutable outcomes and protection of ownership/listed/purchased facts.
- [ ] Complete proof upload/review/rejection screens and an accepted scanning/authenticity procedure. Quarantine or download must not imply approval.
- [ ] Keep reviewer downloads authorized, private, forced as attachments and protected against stale access/tampered files.
- [ ] Add seller draft creation, proof attachment, fee/net preview, publication, price edits and withdrawal to the existing inventory screen. Validate money without floating-point conversion.
- [ ] Complete seller onboarding/readiness against the configured provider. Browser return is not evidence of readiness; drafts must survive incomplete onboarding/review.
- [ ] Verify one live listing per entitlement, current ownership, exact approved proof version and locked-item behavior under competing transactions.
- [ ] After Step 9, verify post-purchase inventory/history and relisting by the new owner; never reactivate the previous seller's listing.

Done when: a verified seller can reach a legitimately published listing through the actual French flow, with every publication gate enforced by the backend.

## Step 7 — Complete availability and notification delivery

Tasks: AV/NOTE tasks in [availability/notifications](docs/tasks/05-availability-and-notifications.md).

- [ ] Complete acceptance for shared one-hour priority windows, current eligibility/proof versions, race cutoffs and buyer/race guards.
- [ ] Verify five-minute batching, deduplication, resumed chunks and original offer deadlines through running workers.
- [ ] Verify preferences and purpose-version evidence independently for global channels, race alerts and newsletter; preserve essential-message behavior.
- [ ] Verify stale recipients, changed preferences, already-owned entries and protected checkouts do not receive actionable availability offers incorrectly.
- [ ] Reconcile uncertain SMTP results using the existing delivery journal and stable message identity; never blindly resend.
- [ ] Finish French alert/inbox surfaces and confirm payload versions/private-field filtering.

Done when: publication produces the correct scoped, deduplicated notification and purchase access without reserving a bib for each waitlist member.

## Step 8 — Complete checkout cancellation and recovery prerequisites

Tasks: CART-01, CHECK-01–05, PAY-02–04. See [checkout](docs/tasks/06-cart-and-checkout.md) and [payments](docs/tasks/07-payments-and-sales.md).

- [ ] Finish cart registration fields and initially unchecked per-nonparticipating-race acknowledgments using accepted versions.
- [ ] Wire atomic authorization and hosted Checkout into French HTTP flows without browser-authorized success or silent partial carts. (Buyer flow wired; real provider acceptance still open — evidence: [checkout start](docs/tasks/evidence/checkout-start-web.md).)
- [ ] Verify all-race sorted locking, buyer/race guards, immutable money/identity snapshots and original provider correlation.
- [ ] Verify protected seller withdrawal requests cancel the whole checkout, deny late queued creation and preserve processing/unknown/paid protections.
- [ ] Reconcile original Sessions/PaymentIntents outside database locks. Timeouts, expired leases and a lost response are not proof that money was not collected.
- [ ] Add verified collected-funds recovery for cancelled/invalid checkout, retaining outstanding exposure until provider evidence confirms recovery.
- [ ] Complete durable scans for missed/unknown outcomes and accepted stuck-operation escalation. Do not replay mutations blindly after provider idempotency retention.
- [ ] Finish pending/cancelled/recovery checkout screens with backend-derived amounts and state.

Done when: each original payment path reaches a verified safe outcome without double payment, partial purchase or premature protection release.

## Step 9 — Consume authenticated events and finalize the whole purchase

Tasks: PAY-03–05, SALE-01–02; audit 6.

- [ ] Claim pending authenticated inbox records with durable retry/fencing state; keep receipt-before-ack and provider-scope deduplication.
- [ ] Retrieve and validate the original Session, PaymentIntent and charge: identity/account scope, amount received/captured, EUR currency and committed local correlation.
- [ ] Recheck authority, fencing, cancellation, listing/entitlement versions and all item eligibility after provider reads, under the shared lock order.
- [ ] Commit every purchase item, ownership transition, sold listing, buyer/race guard update, waitlist removal, seller payable and outgoing intent in one transaction.
- [ ] Route paid-but-invalid/cancelled paths to recovery rather than granting ownership. Complete the withdrawal/finalization race tests.
- [ ] Prove duplicate/out-of-order events, crash/retry, two buyers and multi-item failure cannot create duplicate or partial facts.
- [ ] Build authorized French result/history/owned-bib views and receipt delivery with accepted wording. Distinguish platform purchase from official race registration.
- [ ] Run real Stripe test-mode ST-01–15 and ST-27–31 evidence in addition to deterministic tests.

Done when: backend-confirmed payment creates exactly one complete purchase, and every failed/cancelled path has a verifiable recovery outcome.

## Step 10 — Complete seller finance and support

Tasks: FIN-01–07, SUPPORT-01–05, ORG-03. See [finance](docs/tasks/08-finance-and-scheduler.md) and [support](docs/tasks/09-support-and-organizer-follow-up.md).

- [ ] Create immutable per-item seller obligations with eligibility at payment validation +259,200 elapsed seconds; apply independent holds/readiness gates.
- [ ] Implement durable daily release at 05:00 Europe/Paris, DST handling, missed-run visibility, claims and fencing.
- [ ] Journal original transfer identity/parameters before provider mutation; reconcile uncertainty without duplicate release.
- [ ] Retry definitive temporary failures on the next daily run. Count actual failures, reset on success and deduplicate alerts after three consecutive failures.
- [ ] Keep bank-payout observations separate from platform transfers; a bank failure must not repeat a confirmed transfer.
- [ ] Implement issue reporting/private evidence, acknowledgment policy, payable holds and seller suspension with scoped role/audit checks.
- [ ] Implement super-admin refund approval, capped allocations, technical corrections and permitted exposure recovery under accepted policy. Failed recovery remains outstanding; do not invent bank debit/offset authority.
- [ ] Finish organizer exports/registration follow-up and French seller funds/admin/support screens. Prevent repeated attempts from multiplying reported money.
- [ ] Verify ST-16–26 and overlapping ST-29–30 with real provider evidence, worker restart and competing holds/releases.

Done when: seller money, holds, refunds and recovery are traceable to confirmed provider facts and one authorized durable operation per effect.

## Step 11 — Complete privacy, newsletter, content and reporting

Tasks: PRIV-01–04, NEWS-01, CONTENT-01, REPORT-01–02. See [privacy/content/reporting](docs/tasks/10-privacy-content-and-reporting.md); audit 9.

- [ ] Finish the personal-data inventory, current purpose evidence, historical handling and approved retention rules. UUIDs do not make records anonymous.
- [ ] Implement authenticated access/export/correction/deletion requests with auditable progress and authorized private downloads.
- [ ] Coordinate deletion/anonymization across profiles, credentials/sessions, unsold offers, imports, snapshots, objects, messages, provider payloads and exports. Preserve immutable financial amounts and only justified open-case supporting data.
- [ ] Implement accepted purge schedules and hold review; verify backup restoration honors completed deletion requests.
- [ ] Give newsletter signing a dedicated key with a safe old-key transition so routine secret rotation does not invalidate mailed unsubscribe links.
- [ ] Complete newsletter signup/rejoin/unsubscribe/delivery acceptance with approved purpose-version content and retention.
- [ ] Build approved testimony submission/moderation and French help/contact/legal/privacy/CGV pages; unapproved testimony remains private.
- [ ] Implement deduplicated aggregate signup/listing/purchase metrics without per-user tracking or private payloads; follow the agreed 90-day aggregate retention.
- [ ] Implement scoped organizer/financial reports at the correct transaction grain, including disclosure controls and accepted retention.
- [ ] Finish French privacy/preferences/request/report screens and explain pending exceptions plainly.

Done when: real privacy requests work across actual data locations, approved public content is present and reports cannot expose unauthorized personal or financial facts.

## Step 12 — Pass the complete quality and release evidence

Tasks: REL-01–06; audit 10. See [release readiness](docs/tasks/11-release-readiness.md).

- [ ] Freeze the source snapshot and run `make test`, `make coverage` and `make quality`; record exact totals and exit codes.
- [ ] Reach at least 98% overall source coverage and 100% Domain/Application, targeting meaningful branch coverage. Do not exclude hard code, weaken gates or fake internal database rows.
- [ ] Test reachable native-type guards through real PDO configuration where useful. An isolated connection with `PDO::ATTR_STRINGIFY_FETCHES=true` is a real infrastructure boundary; preserve default runtime settings and verify no unsafe mutation occurs.
- [ ] Run global Infection mutation testing: at least 90% mutation score and 95% covered mutation score; inspect every survivor. A suite with no mutation candidates has no applicable score.
- [ ] Pass PHPStan level 10 without baseline/ignored errors, architecture checks, formatting, configuration/template lint and dependency audit.
- [ ] Exercise complete French browser journeys: signup, organization/race/import, proof/onboarding/listing, alerts/cart/payment, ownership/history/relisting, funds/support/refund and privacy/newsletter.
- [ ] Measure accepted keyboard/focus/form/error/contrast/mobile-layout criteria and representative search/report load.
- [ ] Rebuild both Docker targets and verify restricted roles, private storage, assets, migrations, workers, schedulers, queue failure and graceful restart in staging.
- [ ] Rehearse restore/replay, stuck-payment handling and completed deletion restoration. Verify monitoring, alerts and operator runbooks.
- [ ] Complete an evidence register for every ST-01–31 scenario and all launch requirements, including real provider limitations.

Done when: gates pass on the same source snapshot and runtime/provider acceptance is independently demonstrated. Green line coverage is not a substitute for concurrency or payment evidence.

## Step 13 — Reconcile the backlog and prepare release

Tasks: REL-07–08.

- [ ] Reconcile every task checkbox against its executed evidence. Keep partial work and unresolved decisions open; link evidence from the original task files.
- [ ] Finish the accepted legacy transition: rehearse any data migration, preserve required history, verify no duplicate source of ownership/money and prepare rollback. Do not migrate legacy data without an accepted transition decision.
- [ ] Review the full diff, including new files, secrets, generated artifacts, migrations and operating documentation.
- [ ] Prepare reviewable commit groups and a release report containing actual checks, unresolved risks and rollback limits. Commit only with applicable user authorization.
- [ ] Obtain explicit release/deployment authorization after the implementation and evidence are concrete. Publish/deploy only then.
- [ ] Execute the approved release, verify post-release jobs/readiness/provider state and retain recovery evidence.

Done when: the accepted French launch is deployed with verified operations, closed acceptance tasks and documented remaining deferred scope.

## Resume order

1. Confirm retained legacy exposure and close urgent security findings; fix runtime database privileges.
2. Finish current verification gaps without rewriting green features.
3. Complete required policy decisions alongside seller/import and checkout integration.
4. Finish cancellation/recovery and authoritative whole-purchase finalization.
5. Complete finance/support, privacy/content/reporting and missing French surfaces.
6. Pass global quality, provider/staging/restore acceptance, then request release authorization.

Do not add another parallel roadmap. Update this guide and the linked task evidence as work completes. Never remove database volumes, commit credentials or declare the project finished because only one slice passes.
