# Checkout authorization checkpoint — 2026-10-10

This is executable CHECK-01/CHECK-02 core evidence, not completed hosted payment, cancellation, refund or release certification.

`AuthorizeCheckout::authorize` is the public Application seam. It reconciles each existing seller recipient outside the transaction, then locks all race rows in deterministic order, coordinates the active verified buyer and rechecks cart races before guard/offer locks. Immutable checkout snapshots, per-race protection, exact per-item fees, one money-only financial operation and one outbox intent commit together. The deadline is exactly 900 elapsed seconds.

The constructor requires the provider scope, fee-policy version, HTTPS callbacks, pinned API version and disabled automatic tax. Nonparticipating race acknowledgments must match an explicitly configured text version. No production legal text/version was invented. Registration uses the stored schema/version, rejects required-field violations, and rejects unrequested private fields when no schema exists. Buyer contact and registration data stay in retention-redactable checkout snapshots; provider intent parameters contain money, item identifiers and fixed provider configuration only.

Observed red/green cycles:

- After fixing test environment and fixture setup, the first test failed because `AuthorizeCheckout` did not exist. The implementation passed 1 test/10 assertions.
- An invented client acknowledgment version was initially accepted (red), then rejected after adding the configured-version gate (2 tests/16 assertions).
- Missing fee-policy configuration was initially accepted (red), then failed closed after validating constructor configuration.
- Unrequested private registration data was initially accepted (red), then rejected after the no-schema minimization guard.

Additional real PostgreSQL evidence covers immutable money/profile snapshots, explicit acknowledgment absence, required registration fields, another buyer denied after protection, invalid multi-race cart rollback and complete multi-race totals. Two independent PHP processes are held at the same PostgreSQL race-row barrier; after release exactly one authorizes, leaving one checkout/item/operation. No external provider mutations occur; seller readiness is isolated at the public provider port.

Executed commands:

```sh
docker compose --env-file .env.docker exec -T -e APP_ENV=test php vendor/bin/phpunit tests/Integration/Finance tests/Integration/Checkout
docker compose --env-file .env.docker exec -T -e APP_ENV=test php vendor/bin/phpunit tests/Integration/Checkout/CheckoutAuthorizationTest.php
docker compose --env-file .env.docker exec -T -e APP_ENV=test -e XDEBUG_MODE=coverage php vendor/bin/phpunit tests/Integration/Checkout/CheckoutAuthorizationTest.php --coverage-clover var/checkout-authorization-coverage.xml
docker compose --env-file .env.docker exec -T php vendor/bin/phpstan analyse src/Application/Checkout src/Infrastructure/Checkout tests/Integration/Checkout --no-progress
```

The earlier combined Finance/Checkout regression passed 39 tests/153 assertions. The 13-test authorization coverage checkpoint passed 84 assertions with Application code 100% (45/45 use-case lines plus both DTO constructors). Adapter coverage at that checkpoint was 36/41; empty-cart/missing-profile and data-minimization cases were added afterward. Global coverage, architecture and mutation gates must be rerun against the final complete source tree. The task checkboxes remain open pending their full acceptance.

Not yet implemented here: hosted-session submission, signed webhook confirmation, 15-minute provider reconciliation, whole-checkout withdrawal/recovery, automatic ownership completion or checkout HTTP forms. A prepared outbox message alone is not a successful payment.
