# Buyer checkout status — 2026-10-19

Incremental FE-06 / SALE-02 surface; neither task is complete. The existing design
checkpoint in DESIGN.md records desktop/mobile, regression and coverage checks;
no further redesign was needed for this slice.

`GET /achat/{id}/retour` reads an account-scoped projection of the stored checkout
state and EUR total through Application-owned contracts. It never calls a payment
provider or changes payment/protection state. French copy distinguishes pending,
processing, unknown, cancelled, failed and completed, with an ordinary refresh
link. Missing/foreign checkouts share a French 404; authenticated responses use
`Cache-Control: private, no-store`. Query flags cannot authorize completion.

Observed commands from symfony-app/:

- `docker compose --env-file .env.docker exec -e APP_ENV=test php vendor/bin/phpunit tests/Integration/Web/CheckoutStatusPagesTest.php`: initial red,
  1 test / 5 assertions, missing route (404 instead of login redirect).
- First implementation run reached the cross-account test, then the fixture
  incorrectly attempted to mutate the immutable checkout buyer. PostgreSQL
  rejected that mutation. Corrected the test to sign in as a different buyer.
- Focused green: 1 test / 17 assertions. Expanded existing-state verification:
  2 tests / 43 assertions, exit 0. Covers six states, spoofed success, anonymous
  access, foreign/missing checkout, cache protection and stringified-driver guard.
- `make lint analyse cs-check`: exit 0; YAML/container validation, Deptrac (zero
  violations), PHPStan level 10 and formatting pass.
- `docker compose --env-file .env.docker exec php bin/console lint:twig templates/checkout`:
  exit 0, 1 valid template.
- `make audit`: exit 0; no Composer security advisories.
- `docker compose --env-file .env.docker exec -e APP_ENV=test -e XDEBUG_MODE=coverage php vendor/bin/infection --no-progress --no-interaction --filter=src/Application/Checkout/FindCheckoutStatus.php --test-framework-options='--filter=CheckoutStatusPagesTest'`:
  exit 0, 2/2 mutations killed, MSI and covered MSI 100%. This is focused evidence,
  not a global mutation acceptance result.
- `curl -sS -o /dev/null -w '%{http_code} %{redirect_url}\n' http://localhost:8082/achat/00000000-0000-0000-0000-000000000080/retour`:
  live development stack returns 302 to `/connexion` for anonymous access.

- `make test`: exit 0, **770 tests / 4,774 assertions**, 10m50s.

- `make coverage`: exit 0, **770 tests / 4,789 assertions**, 11m49s.
  Overall **98.05% (2,771/2,826 lines)** and Domain/Application
  **100% (954/954 lines)**; both unchanged coverage gates pass.
- Reviewed all new files, service wiring and documentation; `git diff --check`
  passes. Existing unrelated workspace changes were preserved.

No schema, dependency, asset build or environment-variable changes. No payment
initiation/retry, provider URL rewrites, recovery confirmation, automatic refresh
or purchase finalization is claimed. No provider/staging acceptance, commit or
deployment was performed.
