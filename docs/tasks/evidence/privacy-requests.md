# PRIV-02 — Durable authenticated request intake and scoped progress

Status: implementation in progress. This is the request phase, not completed export, correction or RGPD anonymization.

Existing account administration only suspends/reactivates and revokes credentials. Privacy intake will use existing append-only audit for monotonic, owner-bound business progress and outbox for durable dispatch; acknowledging the outbox is never request completion. No new table is required for the initial serialized journal. Full export/redaction/provider/backup/retention work remains separate and unresolved policies cannot trigger destructive cleanup.

Deletion intake must lock sorted affected races (including all currently owned entitlement races, unsold listing races and all races of buyer/seller protected whole checkouts), then the account, then re-read the lock set. Expansion fails/retries the whole transaction before mutation. All draft/published unsold listings withdraw through existing Selling port while account is active; buyer cancellations use the Finance port. Processing/unknown protections remain until original-path reconciliation. Only afterward suspend account, increment security version and consume marketplace credentials. Request state remains pending/blocked, with explicit scoped financial/policy reasons; no purge or final anonymization is performed here.

Progress access is separate from revoked marketplace sessions: purpose-specific HMAC status authority binds request UUID, owner UUID, exact post-fence account/security version and explicit expiry. A nullable configured positive lifetime has no default; missing policy must refuse before request/fence mutations. The authority grants status only, not export or an application session. Tokens appear only in the receipt response, never audit/outbox/logs. Key rotation invalidates existing authorities; no rotation/continuity guarantee is claimed.

## Token TDD evidence

- `docker compose --env-file .env.docker exec -T -e APP_ENV=test php vendor/bin/phpunit tests/Integration/Privacy/PrivacyProgressTokenTest.php`: red, class missing, 1 test / 0 assertions; signer and public contract did not exist. Green after implementation: 1 / 5, request/owner/version binding and exact microsecond expiry.
- `... --filter 'SignedMalformedStatusAuthority.*request'`: red 1 / 1 (a valid signature with invalid request UUID was accepted). Strict UUID/version parsing added: green 11 / 25 including invalid owner, zero/negative/noncanonical/overflow version, missing field, invalid date and date rollover.
- Full signature/purpose/secret/Base64/expiry set with coverage: green 18 / 39. Additional cases verify existing signature/secret guards; no additional red is claimed.
- Targeted PHPStan clean. CS Fixer applied. Root composition binds APP_SECRET; no signing key or progress lifetime is invented here.

Intake PostgreSQL tests and implementation follow. No production privacy deletion or destructive purge has been performed.

## Resume — 2026-10-13

Observed `PrivacyRequestTest.php` baseline: export intake passed, while seller and buyer deletion cases errored with `Deletion intake is unavailable` (3 tests / 21 assertions / 2 errors). Implemented deletion fencing through the existing inward-owned Selling/Finance ports and real PostgreSQL transaction: sorted affected race locks, account lock, race-set revalidation, unsold listing withdrawal, buyer whole-checkout cancellation, then suspension/security-version increment and credential consumption. No schema was added and no private or financial facts were purged. Protected money remains protected. Retention policy always blocks completion; protected checkouts also add financial-resolution blocking.

A separately observed expiry red failed because a correctly signed authority with a later expiry could read status after the recorded deadline (1 test / 4 assertions / 1 failure). Status lookup now also binds the exact recorded expiry. Privacy regression passed 24 tests / 116 assertions, including missing-policy no-mutation, unprotected deletion/data preservation and rejection of pre-fence status authority.

Composition red: `testContainerProvidesFailClosedPrivacyIntake` reported the missing application service (1 test / 2 assertions / 1 error). Added explicit port/use-case wiring and test-container access. Intermediate attempts exposed a missing cancellation alias and incorrect blank-env conversion; final privacy regression passes **25 tests / 119 assertions**. `PRIVACY_PROGRESS_LIFETIME_SECONDS` remains blank and authenticated intake refuses before mutation until a positive accepted lifetime is configured. This is an Application slice; no HTTP privacy screen or completed anonymization/export is claimed.

Executed commands:

```sh
docker compose --env-file .env.docker up -d --wait
docker compose --env-file .env.docker exec -T -e APP_ENV=test php vendor/bin/phpunit tests/Integration/Privacy/PrivacyRequestTest.php
docker compose --env-file .env.docker exec -T -e APP_ENV=test php vendor/bin/phpunit tests/Integration/Privacy
docker compose --env-file .env.docker exec -T -e APP_ENV=test -e XDEBUG_MODE=coverage php vendor/bin/phpunit tests/Integration/Privacy --coverage-clover var/privacy-coverage.xml
make lint
make analyse
make cs-check
make audit
docker compose --env-file .env.docker exec -T php bin/console lint:twig templates
```

Docker was initially stopped; it was started and the existing development stack became healthy without removing volumes. Development migration status reports all eight migrations applied through `Version20261011000100`. YAML/container lint, Deptrac (781 allowed / zero issues), PHPStan level 10, formatting (350 files / zero remaining changes), 22 Twig templates and Composer audit passed. Composer also emitted its existing repository-ownership warning. PHPStan exposed pre-existing tests with an assertion narrowing a subsequent mutable database read and a PHPDoc placed after an attribute; the tests were corrected without changing production behavior.

The initial broad `make test` was stopped after configuration edits invalidated the running snapshot; it is not green evidence. A final source-frozen `make coverage` and scoped privacy Infection run were started; results follow below. Focused coverage is not task acceptance: ManagePrivacyRequests is 37/44 lines, privacy persistence 45/57, tokens 22/22. Concurrent scope expansion, rollback/replay races and some guards still need acceptance tests; PRIV-02 remains open.

### Broadened acceptance checks

The first frozen full coverage run passed **705 tests / 3,895 assertions**, then failed coverage: **97.05% overall (2,630/2,710)** and **99.26% core (939/946)**. Scoped privacy mutation reported 69 candidates: 42 killed, 17 escaped, 10 uncovered; MSI 60.87%, below acceptance. The initial Infection invocation mistakenly supplied a path as framework options; it was corrected to `--test-framework-options='--filter=PrivacyRequestTest|PrivacyProgressTokenTest'`. No mutation score is claimed for the rejected invocation.

Removed duplicated replay authorization after the green privacy regression, preserving both initial replay lookup and the mandatory lookup after the account lock. Added observable checks for invalid kinds/UUID boundaries, nonpositive and exact one-second lifetime, literal receipt/audit/outbox context, different owner/kind replay denial and atomic rollback when a completed protected checkout refuses withdrawal. The rollback test initially used an invalid fixture state and then omitted its required completion timestamp; those setup errors are not behavior-red evidence.

Three separate PHP-process/PostgreSQL contention cases now wait on a verified account lock before a competing request is committed. Identical requests receive the original status token with one audit/outbox record; different owner/kind requests are denied after acquiring the lock. Child-process Dotenv initially overwrote the explicit lifetime with the inherited blank setting; explicit test environment restoration fixed setup. Regression passed **39 tests / 203 assertions**. PHPStan then required a string assertion for the child database URL; a focused rerun follows this harmless test guard. Production source remains unchanged during final verification.

Local smoke: `/health`, `/ready`, `/` and `/courses` all returned HTTP 200. No production stack, provider call, bank transfer, deployment or destructive purge was performed.

### Final production-source checkpoint

The final production-source full run passed **719 tests / 3,994 assertions**. `make coverage` then reported **100% Domain/Application (943/943)** and **97.34% overall (2,635/2,707)**; the overall 98% gate remains failed. Later additions only extend tests, not production source; report the focused verification alongside this measured snapshot rather than inventing a newer combined count.

Expanded privacy regression passed 40 tests / 217 assertions. It verifies exact outbox effect identity and aggregate/payload versions, plus deletion waiting on a held race before obtaining the account lock. Mutation results progressed from 55/63 killed (87.30%, eight survivors) to **61/63 killed (96.83% MSI and covered MSI, 100% mutation coverage)**, satisfying scoped thresholds. The two survivors were inspected: the unused initial account-version value is overwritten before use; the deletion boolean inversion survived because the subprocess reloaded original source. A further parent-process application test with a separate PostgreSQL lock observer passes (1 test / 8 assertions before the PID type guard), ensuring the mutated Application path itself is observed. A final scoped mutation run follows.

The default ten-second mutation timeout skipped the expanded class instead of exercising mutations. These skips are not passes. Scoped reruns use a generated temporary copy of `infection.json5.dist` with timeout 45 seconds, the original 90%/95% thresholds and all original mutators, and `--only-covering-test-cases`. The temporary file is removed after each run. An unsupported timeout CLI flag and two wrong config filenames were rejected setup attempts, not mutation runs. Global mutation acceptance remains unverified.

The framework-free Application/Domain boundaries are preserved. No additional table, dependency, provider action, public privacy route or policy default was introduced. Final anonymization, exports, corrections, progress transitions, retained-data policies, race-set expansion contention and complete release acceptance remain open; PRIV-02 is not checked complete.

Final scoped run: **41 tests / 226 assertions passed**, PHPStan level 10 and formatting pass. Mutation: **62/63 killed, 98.41% MSI and covered MSI, 100% mutation coverage, zero uncovered/skipped/timed-out candidates**. The deletion-lock inversion is killed by the parent-process lock-order test. The sole survivor changes an overwritten initialization value and has no observable effect; no mutator was disabled. Generated temporary mutation configuration was removed. Overall coverage and global mutation/release gates remain open as described above.
