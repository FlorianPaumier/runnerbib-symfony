# SELL-05 — French listing management controls

Local controls implemented, 2026-10-13. This continues the existing inventory/ListingStore/ManageListings boundaries; it does not create a parallel selling engine or add a dependency. Full proof moderation, real provider acceptance, completed-sale relisting and policy release gates remain separate.

## Draft intake

- Existing `SellerInventoryPagesTest.php` baseline passed: 1 test / 30 assertions.
- Added an owned unlisted-entitlement journey: observed red 1 test / 3 assertions, `The current node list is empty` because the draft form/button did not exist.
- Added authenticated POST draft intake, entitlement-scoped CSRF, stable submitted draft UUID, exact decimal-to-integer-cent parsing and recoverable form errors. The Application facade derives seller identity; creation does not publish, reserve an item or require seller onboarding.
- An intermediate assertion selected the first (existing Paris) fee preview instead of the new Lyon card; this was corrected to check the literal new totals in the rendered page. It is not claimed as a behavior-red.
- Focused Web inventory regression: 2 tests / 48 assertions, green. Full `make test`: **722 tests / 4,050 assertions**, green.
- At `100,10 EUR`, the persisted price is 10,010 cents; buyer preview is 105.11 EUR and seller net 92.59 EUR. Reposting the original form leaves one draft and one draft audit, returning conflict rather than creating another intent.

## Inventory action facts

- Extended the public management snapshot expectation: observed red 1 test / 5 assertions because checkout protection and available approved proofs were absent.
- The same scoped SQL snapshot now supplies the protected-checkout fact and ordered approved proof ID/submission timestamps for the current owner's entitlement. Attached proof/history projection remains separate; no file key or private holder/provider data is added.
- Focused Inventory/Web regression: 8 tests / 117 assertions, green. Full regression passed 722 tests / 4,067 assertions.
- YAML/container lint and Deptrac (791 allowed, zero issues) pass. PHPStan caught a possibly missing submitted listing ID in the page adapter; a local variable with an explicit empty fallback fixes the type/guard. Formatting still required one string-quote change at this checkpoint.

Commands observed:

```sh
docker compose --env-file .env.docker exec -T -e APP_ENV=test php vendor/bin/phpunit tests/Integration/Web/SellerInventoryPagesTest.php
docker compose --env-file .env.docker exec -T -e APP_ENV=test php vendor/bin/phpunit tests/Integration/Web/SellerInventoryPagesTest.php --filter testOwnerCreatesDraft
docker compose --env-file .env.docker exec -T -e APP_ENV=test php vendor/bin/phpunit tests/Selling/SellerInventoryTest.php --filter testAuthenticatedSellerReadsOnlyOwn
docker compose --env-file .env.docker exec -T -e APP_ENV=test php vendor/bin/phpunit tests/Selling/SellerInventoryTest.php tests/Integration/Web/SellerInventoryPagesTest.php
make test
make lint
make analyse
make cs-check
docker compose --env-file .env.docker exec -T php bin/console lint:twig templates
```

No new operator environment variable, table, migration or dependency is introduced by these controls. Existing provider scope/readiness and proof approval remain authoritative.

## Listing lifecycle and protected replacement regression

Observed missing-control red (1 test / 5 assertions), followed by missing 72-hour eligibility copy (1 / 31); the implemented lifecycle journey passed 1 / 41. Current inventory HTTP regression passes **19 tests / 264 assertions** and covers stable draft intent, exact euro parsing, boundary/overflow refusal, CSRF purposes, ownership, approved proof scope, optimistic versions, provider refusal and protected withdrawal.

A real PostgreSQL regression exposed publication of a new listing while a withdrawn listing for the same entitlement retained a processing checkout. After correcting an initial missing test argument (setup failure), meaningful red was 1 test / 7 assertions. Publication protection now checks the locked entitlement rather than only the current listing ID: green 1 / 14. Inventory projection likewise observed red 1 / 15, then green 1 / 15 after checking the same entitlement. The old checkout/cancellation intent stays protected. Full seller checkpoint passed 740 tests / 4,313 assertions; combined Selling/Web/Privacy later passed 94 tests / 857 assertions.

A scoped Selling mutation attempt timed out before scoring. A narrower completed run killed all 69 covered mutants, with 9 uncovered FindListingOffer mutants because the selected test filter omitted listing pages (78 total; MSI 88.46%, covered MSI 100%). The overall scoped threshold failed; this is not global mutation acceptance.

## Stable verification — 2026-10-13

Source held stable: `make test` passed **745 tests / 4,429 assertions**. `make quality` passed its full test/coverage execution (**745 tests / 4,444 assertions**) but stopped at the overall coverage gate: **97.17% (2,717/2,796)** against required 98%; Domain/Application **100% (943/943)** passes. No exclusion or threshold was changed. Separate lint/container validation, PHPStan level 10, Deptrac (815 allowed, zero issues), formatting (352 files, zero changes), 24 Twig templates and Composer audit pass. Global mutation acceptance has not been demonstrated.

Both development and production PHP/web image builds passed. An isolated production Compose project migrated its fresh database through Version20261011000100 and ran using a provisioned restricted runtime role (no superuser, create DB/role, replication or bypass-RLS rights). Health/readiness/public pages returned 200; anonymous account pages redirected to login. The real privacy use case suspended a synthetic account and its body-only status code returned pending status over HTTP without exposing its email or recording the code in web access logs. The stack was stopped with its volumes preserved. No actual production deployment, commit, existing-volume reset or provider interaction occurred.
