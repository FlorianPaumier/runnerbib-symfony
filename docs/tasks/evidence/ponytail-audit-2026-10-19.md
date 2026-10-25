What this repo does: Runnerbib contains independent marketplace applications. The active Symfony application lets runners list race bibs, join race waitlists and start hosted payments; organizers manage race eligibility. The audit assumes the accepted early load of about 100 users/day, with concurrent buyers and background workers.

The user's explicit auto-fix request overrides the audit skill's report-only default. Changes stay within `symfony-app`; existing uncommitted work is preserved.

**Must fix — corrected**

1. **The built production application cannot warm its cache** (`config/packages/routing.yaml:5`).
   - **What this is:** Symfony needs a trusted base origin when generating URLs from console commands.
   - **Problem:** Routing required `DEFAULT_URI`, but the standalone production environment supplies only `APP_BASE_URL`. The built image strips development environment files, so cache warmup failed with “Environment variable not found: DEFAULT_URI”.
   - **Fix:** Use the already configured `APP_BASE_URL` for the router and remove the obsolete development variable. A disposable production-image test first reproduced the failure and now passes using only the declared Compose environment; CI runs it after building production images.
   - **If we skip it:** Production PHP startup fails during cache clear before serving requests.

2. **A crashed worker strands its payment event** (`src/Infrastructure/Finance/DbalProviderEventInbox.php:30`).
   - **What this is:** The inbox gives a worker a 60-second lease, meaning temporary permission to process an event.
   - **Problem:** After a worker crashes, its event stays `processing`. The old selection only retried `pending` and `failed` events, so even an expired lease was never selected again.
   - **Fix:** Reclaim processing events at the lease deadline. A real PostgreSQL test proves exclusion at 59 seconds and recovery at 60 seconds.
   - **If we skip it:** The webhook remains durably received but never processed again through this inbox.

3. **Completing one event changes another platform's event** (`src/Infrastructure/Finance/DbalProviderEventInbox.php:56`).
   - **What this is:** Event references are unique within a provider/platform scope, while each local inbox row has its own UUID.
   - **Problem:** Two scopes can store `evt_shared`. If the same worker claims both, completion by event reference changes both rows and returns false because two rows changed.
   - **Fix:** Carry the local row UUID in the claimed event and use it for completion and retry. The regression checks that exactly one row completes and the other remains processing.
   - **If we skip it:** One event can be marked complete before its own processing finishes.

4. **An older worker can overwrite a newer claim** (`src/Infrastructure/Finance/DbalProviderEventInbox.php:56`).
   - **What this is:** Each new lease increases a generation number, identifying that exact claim.
   - **Problem:** After lease recovery, a delayed callback using a reused worker name can still complete or retry the replacement worker's event. The old updates did not check the generation.
   - **Fix:** Carry the generation with the claimed event and require it in both update predicates. Separate completion/retry cases prove that the old claim fails and the current claim succeeds.
   - **If we skip it:** Late work can prematurely complete or requeue work owned by a newer claim.

5. **Invalid registration answers start a payment** (`src/Presentation/Http/CartController.php:105`).
   - **What this is:** The HTTP boundary converts form strings into the integer and boolean values required by a race.
   - **Problem:** `38.5`, scientific notation and an overflowing number were cast to integers. `perhaps` became boolean false; all four inputs could create a protected checkout and redirect to the provider.
   - **Fix:** Validate integers before conversion and preserve an invalid boolean as null for domain validation to reject. HTTP tests use the real application/PostgreSQL and a controlled provider port; valid integer zero and boolean false also remain valid and retain their stored types.
   - **If we skip it:** The stored registration can differ from the buyer's submitted answer while still appearing valid.

6. **A declined transfer acknowledgment counts as accepted** (`src/Presentation/Http/CartController.php:82`).
   - **What this is:** Buyers must explicitly acknowledge the transfer procedure for each nonparticipating race.
   - **Problem:** Posting the race field with value `0` was accepted because only its key was checked.
   - **Fix:** Pass only fields whose value is the form's checked value `1` to the use case. A real HTTP regression proves that `0` creates no checkout and calls no provider.
   - **If we skip it:** Payment can start without the required acknowledgment.

**Should fix — corrected**

7. **The regular suite has too little headroom below two minutes** (`Makefile:1`, `Makefile:20`).
   - **What this is:** ParaTest executes the complete PHPUnit suite against isolated real PostgreSQL databases.
   - **Problem:** The initial 831-test run took 113.238 seconds. A global `DOCKER_DEFAULT_PLATFORM=linux/amd64` forced emulated PHP on this ARM64 machine; tests were scheduled by whole class, CLI OPcache was disabled, and there was no duration gate.
   - **Fix:** Use the Docker server's native architecture by default, enable CLI OPcache, distribute individual tests/data sets, explicitly disable Xdebug for ordinary tests, and fail a fully completed run at 120 seconds or longer. CI executes the ordinary suite and budget guard before the separate quality checks.
   - **If we skip it:** A small test addition or resource contention can silently push routine feedback past the requested budget.

8. **Every credential-mail test repeats an already applied migration** (`tests/Integration/Operations/CredentialMailTest.php:34`).
   - **What this is:** Each test receives a copy of the database template, which has already run all migrations.
   - **Problem:** Setup launched another Symfony migration subprocess for every case, although those migrations were already applied.
   - **Fix:** Delete that one setup statement. The dedicated apply/rollback/replay test and its migration helper remain unchanged.
   - **If we skip it:** The suite pays for unnecessary process startup and database inspection in every credential-mail case.

9. **The scheduler smoke test still expects the old job list** (`tests/Infrastructure/checkout-scheduler.py:43`).
   - **What this is:** The test checks that a payment-job failure does not stop the remaining jobs and that shutdown is clean.
   - **Problem:** The scheduler now also runs provider events, seller reconciliation and retention, but the test still expected only three jobs per cycle. Its eight-line wait could also stop before the expanded second cycle completed.
   - **Fix:** Expect all six jobs and wait for both complete seven-line cycles before shutdown. The observed failing test now passes.
   - **If we skip it:** The infrastructure check fails despite the intended job sequence, and its polling threshold can race the longer cycle.

**Observed red → green evidence**

- Initial full ParaTest run: **831 tests / 5,206 assertions**, **113.238 s**, pass. Another serial suite was already running; it was left untouched.
- Lease deadline regression: red at the expected 60-second reclaim, then green (**1 test / 5 assertions**).
- Intermediate full run after lease recovery and scheduling changes: **832 tests / 5,211 assertions**, **104.465 s**, pass.
- Cross-scope completion regression: red because completing one claim changed two rows. Old-generation completion and retry regressions: both red because the old claim was accepted.
- Inbox and event-processing regression run after the claim-identity fix: **24 tests / 148 assertions**, pass. Full follow-up: **835 tests / 5,229 assertions**, **118.756 s**, pass while the native image was building.
- Invalid registration HTTP regressions: all four red with HTTP 302/provider redirect instead of 409; then all four green (**24 assertions**).
- Declined acknowledgment regression: red with HTTP 302/provider redirect. Final checkout-page run: **10 tests / 83 assertions**, pass, including the valid zero/false case.
- Budget guard: **3 Python tests**, initially one failure because an exceeded budget was accepted; all three pass after the Make change. They cover success, exceeded budget and preserved test failure.
- First complete native run: **841 tests / 5,269 assertions**, **82.909 s PHPUnit / 83 s command wall time**, pass. The same database-per-test isolation and all original scenarios remain enabled.

**Final validation**

- Coverage: **841 tests / 5,269 assertions**, **110.590 s**; **98.22% overall (3,094/3,150 lines)** and **100% Domain/Application (1,049/1,049 lines)**. Both configured gates pass.
- Inspected all 56 uncovered source statements: mostly defensive invalid-storage/driver guards, filesystem failure cleanup, changed-scope refusals and fallback controller responses. No Domain/Application statement is uncovered; 100% overall is not claimed.
- Symfony YAML/container lint and Deptrac pass, with **0 violations / 0 warnings / 0 uncovered dependencies**. PHPStan level 10 passes.
- The first `make quality` stopped at five unused test imports. `make cs-fix` removed those imports; the subsequent complete formatting check and dependency audit pass. The remaining quality stages are run explicitly rather than calling the earlier aggregate run successful.
- Development PHP/web images and all standalone production images build. Production-image cache clear/warmup and container lint pass after the origin fix, without starting the production stack.
- Python infrastructure checks pass: Compose credential separation (4), restricted-role grants (4), scheduler (1), budget (3) and built production runtime (1).
- Repeat complete suite: **841 tests / 5,269 assertions**, **93.013 s PHPUnit / 93 s command wall time**, pass while the mutation initial run was active. Both measured native ordinary-suite runs meet the default budget.
- The budget regression also uses a controlled external clock (start 100, finish 221) to prove that a 121-second run fails the positive 120-second budget without actually sleeping.
- Full global mutation run is still running; this section will be updated before handoff.

**Commands and settings**

- `make test`: full ordinary suite; defaults `TEST_PROCESSES=auto`, `TEST_MAX_SECONDS=120`, Xdebug off and CLI OPcache on.
- `make coverage`: full coverage run with Xdebug, unchanged 98% overall and 100% Domain/Application gates. Coverage/mutation/build/startup costs are outside the ordinary-suite budget.
- `make quality`: coverage, configuration/architecture, PHPStan, formatting, mutation and dependency audit.
- `python3 tests/Infrastructure/test-runner.py`: fast Make-boundary regression tests.
- `make build`: development PHP/web images, using the Docker daemon's native architecture by default. `DOCKER_PLATFORM=linux/amd64` or `linux/arm64` explicitly overrides this for development Make commands.
- The standalone production image build uses existing production variables with disposable validation values; no production stack is started and no production database is modified.

Verdict: The nine confirmed findings above are fixed. Quality results and repeat timing are recorded above; this is not release approval.

Lean: -7 lines removed (one redundant setup statement, five unused imports and one obsolete environment variable), -0 dependencies. No new dependency or production layer was added.

Not checked: The independent `web-app`, `api-bib` and `bib-waitlist` applications were inventoried but not deeply audited or changed. This was a risk-focused review of the active Symfony app, not a line-by-line review of every file. Real Stripe behavior, real production credentials/role provisioning, purchase finalization, collected-funds recovery and a hosted CI run remain separate acceptance evidence; this run uses controlled provider ports. No unconditional runtime guarantee is possible on arbitrary hardware or under arbitrary contention; the new budget gate reports a miss without truncating the suite. No commit, publish or deployment was performed.
