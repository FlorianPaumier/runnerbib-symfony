# Backlog execution progress — 2026-10-08

The user authorized specialized subagents to execute all defined tasks. Implementation proceeds through public Domain/Application, HTTP, migration and controlled provider boundaries with red → green → refactor and real isolated PostgreSQL. Root owns integration and shared configuration. No production deployment or external messages are authorized by this implementation request.

| Task/work | State | Evidence |
|---|---|---|
| BASE-01 | Complete | [Development migration](base-01.md) |
| BASE-02 | In progress; French home/search/filter/error pages and production asset smoke | [Web foundation](web-foundation.md) |
| BASE-03 | Integration tests green; specialist finishing quality/evidence | Durable transaction, audit, outbox, concurrency and real Messenger boundaries |
| BASE-04 | Assigned to infrastructure specialist; local persistent queue next | Cloud environment remains unverified |
| Runtime grants safety finding | Fixed; isolated PostgreSQL checks pass, BASE-06 remains open | [Runtime grants](runtime-grants.md), [schema review](schema-review.md) |
| SELL-01 | Complete; 19 tests, 42/42 mutants killed | [Fee policy](fees.md) |
| RACE-01 / RACE-03 | Specialist implementing persistence, discovery/detail; focused tests and coverage green, mutation review in progress | [Race evidence](races.md) |
| RACE-06 | Root implementing French public discovery/detail screens | Focused search HTTP tests green; detail pending query seam |
| AUTH-01/02/04/05 | Identity specialist implementing registration, verification/reset, roles and session security | Real isolated PostgreSQL tests green; HTTP implementation in progress |
| ORG-01 / ORG-02, RACE-02 | Assigned next to race/organization specialist | Membership, scoped writes and audit |
| AV-01 / NOTE-01 | Assigned next to infrastructure specialist | After durable queue foundation |
| Remaining launch tasks | Pending dependency completion; continue assigning specialists as capacity opens | [Task index](../README.md) |

Latest full-suite checkpoint: 103 tests/336 assertions passed before subsequent HTTP additions. This is a checkpoint, not final global quality acceptance. New focused tests are added continuously. Full coverage/static architecture/style/mutation checks must pass before completion claims.

Product decision defaults and staging configuration have been requested asynchronously. Implementation does not infer answers from elapsed time. Real Stripe/Google/Cloud checks, legal/retention decisions and production release evidence remain explicit gates. Task checkboxes represent passed acceptance, not assignment or partial code. Mobile/chat/English tasks are explicitly deferred by the launch plan.

Additional checkpoints: global PHPStan level 10 passed; Deptrac reports zero violations. Production Docker snapshot built and served French home/search/login plus readiness against its isolated migrated database. The runtime has `pcntl`; infrastructure specialist verified SIGTERM graceful exit in a separate worker container. Root organization dashboard test passes authenticated membership, anonymous login redirect and tampered-organization denial. [Search diagnostics](race-search-performance.md) preserve full query plans and keep RACE-05 open pending budgets.

## Resume checkpoint — 2026-10-10

Specialists resumed identity/selling, seller onboarding and availability/notifications. Root added French waitlist and inbox HTTP surfaces (Web17 tests/140 assertions), explicit service composition, Redis cache/session runtime smoke and whole-job CI Redis configuration. Both Docker builds passed; an isolated production stack migrated through Version20261009000200 and returned200 at home/search/login/health/readiness, then stopped with volumes preserved.

Fresh full coverage run passed296 tests/1410 assertions but thresholds FAILED: overall93.81%1152/1228 lines, core99.78%445/446. This run is a moving implementation checkpoint; later source/tests are not represented. No exclusion or threshold relaxation was made. Root added in-process real queue CLI verification to cover process-only console code; specialists are adding observable missing paths. Cart work began with pure per-item fee totals, self-purchase/one-item-per-race/overflow guards and real persisted cart entries that create no reservation. CART01 remains in progress, registration contract and atomic checkout still pending.

Continuation adds atomic multi-race checkout authorization with real PostgreSQL contention, imported ownership proof approval, configured CSV preview/persisted imports, bounded availability batching and safe offer links in the recipient inbox. Root observed invalid stored offer price returning500, then implemented fail-closed404 (2 HTTP tests/29 assertions). Notification projection red→green protects private fields and unknown payload versions; focused inbox/unit regression passes21 tests/110 assertions. Runtime availability command returns `Processed batch chunks: 0`; all18 Twig files lint successfully. Global PHPStan level10 and Deptrac passed the earlier source snapshot.

A broad424-test run reported2068 assertions and one failure because it loaded the old NotificationView class while the new Twig template was written. The affected flow passes in a fresh focused process. This broad run is not reported as green; a stable combined rerun is still required. A newer full coverage run is in progress, so earlier coverage percentages remain historical. Current personal-data locations and outstanding retention/evidence controls are mapped in [data inventory](../../privacy/data-inventory.md); PRIV tasks stay open.

## Continued execution — 2026-10-11

Root finished the organizer access-request surface: observed missing-route red, then request green; blank-name red500→green400; duplicate-membership red500→green409 with rollback. Organization HTTP/application regression passes28 tests/138 assertions. The persistence adapter maps uniqueness conflicts after rollback to the inward exception contract instead of exposing Doctrine exceptions through HTTP.

Independent French newsletter signup/unsubscribe is integrated with purpose-signed generation-bound links, per-IP rate limiting, CSRF signup, optional reason and no account/waitlist side effects. HTTP regression passes2 tests/35 assertions before the optional POST-reason addition; the combined newer Web+Notification unit run passes41 tests/310 assertions. The specialist observes29 tests/131 assertions for lifecycle, concurrent signup, token validation and delivery. The welcome intent stores signed authority rather than copying recipient email; delivery re-reads the current subscription. Retention and consent-version approval remain open.

Both development and production Docker images build successfully. Nginx's existing image configuration initially failed the new unsubscribe-token log test; after applying the built configuration and reload, the synthetic token is absent and `/newsletter/desinscription/[redacted]` is present. This checks access logs only. The isolated production stack migrated forward and served home/search/newsletter/login/health/readiness200; anonymous cart/organizer-request returned302. It was stopped without removing its volumes. Development safely applied the three pending forward migrations through Version20261010000200; no database reset occurred. Draft-withdrawal rollback limits remain in the specialist evidence.

A new full-suite coverage checkpoint is running. The last measured global gates still failed95.25%overall/98.92%core; later focused tests are not substituted for a new combined measurement. Composer audit reports no vulnerability advisories. Remaining payment reconciliation, private file scanning/approval, financial/sale/privacy/reporting workflows and provider/operator/legal release evidence remain unfinished tasks.

The subsequent full run finished556 tests/2666 assertions with15 setup errors: migration subprocesses loaded a temporarily invalid reconciliation SQL string during an edit. This is not a passing suite. After correction, all Checkout integration tests pass39 tests/290 assertions. Reconciliation and fail-closed private storage ports are wired; Symfony container validation and global PHPStan level10 pass. A private profile HTTP cache test observed missing `no-store` (red1 test/4 assertions); the response header fix passes1 test/27 assertions. Newsletter specialist final scope passes30 tests/136 assertions with Application100% and overall98.82%; global coverage and mutation acceptance remain pending.

With implementation files held stable, the fresh global run passes590 tests/2961 assertions. Coverage is100% Domain/Application (836/836), satisfying the core gate; overall96.55% (2239/2319) still fails the98% gate. Global PHPStan level10, Deptrac (689 allowed, zero violations/errors/warnings/uncovered), formatting (308 files, zero changes),20 YAML files,20 Twig templates and container validation pass at this checkpoint. Composer audit finds no advisories. Global mutation and the overall coverage target remain unverified/unsatisfied; these results do not mark the entire backlog complete.

## Stable checkpoint — 2026-10-12

The held-source coverage run passed651 tests/3615 assertions. Domain/Application reached100% (899/899); overall97.01% (2436/2511) still fails the98% gate. PHPStan10, Deptrac749 allowed with zero issues, formatting335 files, YAML/container validation and Composer audit passed at that snapshot; both Docker builds and assets passed. Subsequent PDO, import, runtime-role and privacy/payment changes require a new stable full run. Global mutation acceptance remains unverified.

The user confirmed none of the three legacy applications remain deployed or supported. They remain reference applications; their audit remediations are not current launch work. This does not decide retained-data migration. See [legacy boundary](legacy-boundary.md).

## Resume checkpoint — 2026-10-13

Continued the interrupted privacy-intake slice without touching legacy applications. Deletion locks/revalidates race scope, withdraws unsold listings through Selling, requests buyer whole-checkout cancellation through Finance, then suspends/revokes credentials atomically. Status authority binds original expiry and post-fence account version; unresolved retention and protected financial work remain explicit blockers. No purge or final anonymization is claimed. [Privacy evidence](privacy-requests.md) records observed failures, refactoring, real PostgreSQL contention and scope limits.

Full production-source run passes719 tests/3994 assertions, core coverage100%943/943, overall97.34%2635/2707 still fails98%. Later test-only additions pass41 privacy tests/226 assertions. PHPStan10, Deptrac781 allowed/zero issues, formatting and configuration checks pass. Scoped mutation acceptance is recorded in the privacy evidence; global mutation/provider/release gates remain open. Docker was started and the preserved development stack is healthy; no commit, production deployment or database reset occurred.

## Seller controls and privacy web continuation — 2026-10-13

Implemented French listing management and privacy request/status pages through existing application boundaries. A real PostgreSQL regression fixed replacement-listing publication around an entitlement with a protected checkout. Details: [seller web](selling-web.md), [privacy web](privacy-web.md). The 86-task backlog is not complete; policy, providers, finance/support, retention and release journeys remain open.

## Stable verification — 2026-10-13

Source held stable: `make test` passed **745 tests / 4,429 assertions**. `make quality` passed its full test/coverage execution (**745 tests / 4,444 assertions**) but stopped at the overall coverage gate: **97.17% (2,717/2,796)** against required 98%; Domain/Application **100% (943/943)** passes. No exclusion or threshold was changed. Separate lint/container validation, PHPStan level 10, Deptrac (815 allowed, zero issues), formatting (352 files, zero changes), 24 Twig templates and Composer audit pass. Global mutation acceptance has not been demonstrated.

Both development and production PHP/web image builds passed. An isolated production Compose project migrated its fresh database through Version20261011000100 and ran using a provisioned restricted runtime role (no superuser, create DB/role, replication or bypass-RLS rights). Health/readiness/public pages returned 200; anonymous account pages redirected to login. The real privacy use case suspended a synthetic account and its body-only status code returned pending status over HTTP without exposing its email or recording the code in web access logs. The stack was stopped with its volumes preserved. No actual production deployment, commit, existing-volume reset or provider interaction occurred.

## Overall coverage acceptance — 2026-10-13

Added public boundary/rollback tests without changing production source or gate rules. Final `make test` passes 763 tests / 4,647 assertions; `make coverage` passes 763 tests / 4,651 assertions. Overall coverage **98.03% (2,741/2,796)** and core **100% (943/943)** both pass. PHPStan10, formatting, Symfony configuration and Deptrac pass. [Detailed evidence](coverage-98.md) preserves the preceding exact-count failure despite its rounded 98.00% display. Global mutation/provider/release acceptance remains open.
