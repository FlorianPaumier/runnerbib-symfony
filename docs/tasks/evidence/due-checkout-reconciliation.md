# Bounded checkout reconciliation — 2026-10-19

Incremental PAY-04 / CHECK-05 runtime work. Purchase finalization, authenticated
event consumption, paid-conflict recovery and real Stripe acceptance remain open.

## Implementation

`ReconcileCheckout::reconcileDue` reuses the existing reconciliation service and
Application-owned store. The scope-bound query selects pending/processing/unknown
checkouts at their initial deadline or after a cancellation request, excludes live
leases, and limits batches to 1–1000. Each checkout is claimed/fenced by the
existing service before provider inspection outside database locks.

`payment_attempts.last_reconciled_at` records every completed inspection. A given
cancellation request is retried no more than once per 60 seconds. Earlier status
checks cannot postpone a newly due deadline or withdrawal. Old uninspected work
precedes recently inspected work so unknown/processing results do not monopolize
a bounded batch. Only an authoritative closed path releases protection; paid,
processing and unknown outcomes retain it. No new financial attempt is created.

`app:checkout:reconcile --limit=100` and `make reconcile-checkouts` expose this
service. Existing scope/provider configuration is reused. CLI errors are sanitized
and nonzero; the production scheduler continues other jobs if this payment job
fails, then sleeps 60 seconds between cycles. Existing SIGTERM handling remains.

## Observed red → green

- First due-deadline test: missing `reconcileDue`, 1 test / 3 assertions, error.
- Expanded due tests: 8 tests / 25 assertions, missing public methods caused
  6 errors / 2 failures. Command test: missing `app:checkout` namespace, 1 error.
- Initial focused implementation: 14 tests / 113 assertions, exit 0.
- Added early-poll deadline regression: expected 1 reconciled checkout, got 0.
  Adjusted retry eligibility so a recent early check cannot delay a new deadline.
- Final focused checkout/command tests: 17 tests / 134 assertions, exit 0.
  Includes three protected payment outcomes, expiry/cooldown equality boundaries,
  withdrawal, provider scope, live/crashed leases, batch fairness, multiple-item
  counting, invalid limits and sanitized command failures.
- Scheduler process test initially had a harness pipe/shutdown race. After
  isolating its process group and cleanup, reverting the scheduler integration
  produced the intended missing-command assertion failure; restoring it passed.
  This exercises two cycles despite a failing payment CLI, plus clean SIGTERM.

## Verification commands

Run from symfony-app/:

```sh
docker compose --env-file .env.docker exec -e APP_ENV=test php vendor/bin/phpunit tests/Integration/Checkout/CheckoutAuthorizationTest.php tests/Integration/Console/CheckoutReconciliationCommandTest.php --filter='testDueReconciliation|CheckoutReconciliationCommandTest'
python3 tests/Infrastructure/checkout-scheduler.py
python3 tests/Infrastructure/production-compose.py
sh -n docker/php/scheduler.sh
docker compose --env-file .env.docker config --quiet
make build
make prod-build
make lint analyse cs-check audit
make test
make coverage
docker compose --env-file .env.docker exec -e APP_ENV=test -e XDEBUG_MODE=coverage php vendor/bin/infection --no-progress --no-interaction --filter=src/Application/Finance/ReconcileCheckout.php --test-framework-options='--filter=CheckoutAuthorizationTest'
```

Production configuration/build validation supplied synthetic required values for
APP_SECRET, NEWSLETTER_SIGNING_SECRET, RUNTIME_DATABASE_URL, MAILER_DSN,
APP_BASE_URL and MAILER_FROM; no deployed configuration or credentials changed.
Both Compose configurations validate; scheduler test (1) and production Compose
tests (4) pass. Development and production images build. YAML/container/Deptrac,
PHPStan level 10, formatting and Composer audit pass.

## Observed results (2026-10-19)

- Full regression: `make test` → 788 tests, 4,942 assertions, exit 0.
- Coverage: `make coverage` → 788 tests, overall 98.04 % (2,797/2,853 lines),
  Domain/Application 100.00 % (961/961); both gates pass.
- Focused mutation on this slice's Application files
  (`CheckoutStatus`, `FindCheckoutStatus`, `ReconcileCheckout`): 39 mutants,
  39 killed, mutation code coverage 100 %, covered MSI 100 %.
  The first run escaped one mutant (`ReconcileCheckout::reconcile` dropping
  `HostedCheckoutProvider::assertConfigured()`), because no test proved that an
  unconfigured provider must refuse reconciliation before the checkout is
  claimed. `testUnconfiguredPaymentProviderCannotReconcileAndKeepsProtection`
  now proves that refusal and that protection, the race guard and the fencing
  generation stay untouched; the rerun killed all 39 mutants.
- `make lint analyse cs-check audit` → YAML/container/Deptrac, PHPStan level 10,
  PHP CS Fixer and Composer audit all pass.
- Production and development images build; the isolated production smoke stack
  ran the scheduler cycle with restricted database credentials and exited 0 on
  SIGTERM.

The mutation run needs `APP_ENV=test` (Symfony's `framework.test` container),
absolute source paths and a per-test timeout above the default 10 s, because the
covering integration test creates an isolated PostgreSQL database. The slice's
own configuration is unchanged; only the local verification invocation differs.

No schema, dependency, business-policy or frontend changes. No actual Stripe
calls, new payment submissions, financial transfers, commit or deployment.
