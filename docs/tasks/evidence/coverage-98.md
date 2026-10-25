# Overall coverage gate — 2026-10-13

User requested raising measured overall source coverage to at least 98%. Changes are tests only; production source, coverage exclusions and thresholds are unchanged.

Baseline: 745 tests / 4,444 assertions in the coverage run; overall 97.17% (2,717/2,796), Domain/Application 100% (943/943). At least 24 additional uncovered statements must be exercised to pass the unchanged gate.

Added observable boundary scenarios:

- Privacy intake/fencing require an owning transaction; missing/suspended owners and real PDO stringified account/listing/version facts fail closed without writes. Incomplete durable request origins cannot grant progress.
- Append malformed progress records through the real audit table; public status returns a private/no-store, no-referrer 503 without receipt/private information. Original audit remains immutable.
- Notification ports require owned transactions; unknown offer locks are harmless; a completed batch cannot accept another schedule; malformed digest sizes, unsupported mail outcomes and notifications without availability events cannot advance delivery.
- Malformed public availability projections cannot hydrate purchase offers.
- A stored non-string role cannot authenticate or grant administrative authority; real PDO stringified optional preferences cannot become permissions.
- An incomplete import preview rolls back import creation, entitlements and audit. An existing buyer checkout guard prevents an ownership claim and preserves payment protection.

These verify existing behavior, so no missing-behavior red run is claimed. Initial identity expectation assumed enabled optional preferences; the stored defaults are false, and the expectation was corrected. Initial claim test repeated its already-seeded fixture and failed setup with duplicate accounts; duplicate seeding was removed. Neither is claimed as product-red evidence.

Focused privacy/store/web verification passed 12 tests / 179 assertions. Notification/hydrator/identity combined run passed all notification/hydrator scenarios but failed the erroneous identity default assertion (36 tests / 210 assertions, one failure); corrected identity run passed 2 / 11. Import boundary passed 1 / 7; corrected checkout guard passed 1 / 8.

Commands: container PHPUnit focused files/filters; `make cs-fix`; `make analyse cs-check lint`; `make test`; `make coverage`; `git diff --check`.

Fresh static checks pass: PHPStan level 10, 355-file formatting dry-run, 20 YAML files, Symfony container validation and Deptrac (815 allowed, zero issues). Full regression and coverage measurement are recorded after completion below.

No image rebuild/provider test/mutation rerun is required for these test-only changes; no commit, deployment, production fixture mutation, volume deletion or coverage exclusion was performed. Existing global mutation/provider/release acceptance remains separate.

## First stable measurement

`make test` passed 762 tests / 4,606 assertions. `make coverage` passed 762 tests / 4,597 assertions, core 100% (943/943), but the coverage gate failed: overall 2,740/2,796 (97.997%, displayed as 98.00%). The gate compares exact counts and correctly rejected the rounded percentage.

Added one further public HTTP boundary: nested, numeric and null organizer import-update fields return 400/no-store before any import, entitlement or audit mutation. Focused run passed 1 test / 18 assertions; fresh PHPStan10 and formatting pass. Production source remains unchanged. A new full measurement follows below.

## Final stable measurement — PASS

`make test`: **763 tests / 4,647 assertions**, exit 0.

`make coverage`: **763 tests / 4,651 assertions**, exit 0. The unchanged exact-count gate passes:

- Overall: **98.03% (2,741/2,796 executable lines)**, minimum 98%.
- Domain/Application: **100% (943/943 executable lines)**, minimum 100%.

The 18 added scenarios cover 24 previously uncovered statements. No production source or coverage rules changed. PHPStan10, formatting, configuration/architecture checks and final diff whitespace review pass. Mutation/provider/release acceptance remains separate and was not rerun for these test-only changes.
