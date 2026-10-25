# SELL-01 — Exact EUR fee policy

Completed 2026-10-08 through the public plain-PHP `FeePolicy::quote(int $priceCents): FeeQuote` seam. No provider calls, database changes or controller calculations are involved.

The immutable quote exposes EUR currency, original price, buyer fee/total and seller margin/net in integer cents. Buyer fee is max(500 cents, 5%); seller margin is max(500 cents, 7.5%). Each fee rounds half-up independently per bib. Prices at or below 500 cents cannot leave positive seller net and are rejected. Buyer totals outside integer range throw OverflowException. Quotient/remainder arithmetic avoids multiplying an entire price or adding rounding offsets to it, so large valid prices do not become floats.

## Observed red → green → refactor

Command for each slice: `docker compose --env-file .env.docker exec -e APP_ENV=test php vendor/bin/phpunit tests/Unit/Finance`.

1. €100 quote: red, FeePolicy class missing (1 test, 0 assertions); green after the quote/result implementation (1 test, 6 assertions).
2. Zero seller net: red, missing InvalidArgumentException (2 tests, 7 assertions); green after rejecting prices ≤ 500 (2 tests, 7 assertions).
3. Maximum integer price: red, intermediate overflow caused TypeError instead of OverflowException (3 tests, 8 assertions); green after bounded arithmetic and explicit total guard (3 tests, 8 assertions).
4. Supplementary scenario coverage: €200, minimum accepted price, per-bib minimum, buyer and seller half-cent boundaries, seller minimum crossover, very large valid price, exact largest accepted buyer total, first rejected next-cent price, negative/zero prices. These verified existing behavior; no additional red run is claimed.
5. Refactor: Symfony formatting and explanatory arithmetic comments; final full suite green.

## Executed verification

- Focused PHPUnit with Xdebug/Clover: **19 tests, 24 assertions**, green.
- Clover `var/coverage/fees.xml`: FeePolicy **7/7** lines, FeeQuote **1/1** lines, **100% Finance line coverage**. This is scoped coverage, not a claim about concurrent work elsewhere.
- `vendor/bin/phpstan analyse src/Domain/Finance tests/Unit/Finance --no-progress --memory-limit=512M`: level 10, no errors.
- PHP CS Fixer on the two owned directories: completed; one test file formatted.
- `vendor/bin/infection --no-progress --no-interaction --filter=src/Domain/Finance --test-framework-options='--filter=FeePolicyTest' --with-uncovered`, APP_ENV=test, XDEBUG_MODE=coverage: **42 mutants generated, 42 killed, 100% MSI/covered MSI/mutation coverage**, no survivors. The filter option emits a deprecation warning; it does not affect execution.
- `make test`: **56 tests, 145 assertions**, green. Other agents were implementing independent tasks concurrently; root performs the final combined quality gate.

Quote is per bib. Checkout must sum separate quotes rather than apply minima once to a whole cart. This task does not implement listing HTTP preview or checkout snapshots; those belong to their dependent tasks.
