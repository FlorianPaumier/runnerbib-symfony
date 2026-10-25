# PAY-01 seller onboarding evidence (in progress)

Assigned backend slice: shared seller Recipient account, hosted onboarding, authoritative readiness and durable creation identity. No task acceptance checkbox is closed.

Official sources inspected 2026-10-09:
- https://github.com/stripe/stripe-php/releases: latest stable v22.0.0 (October 1).
- https://raw.githubusercontent.com/stripe/stripe-php/v22.0.0/lib/Util/ApiVersion.php: API 2026-09-30.endive.
- https://raw.githubusercontent.com/stripe/stripe-php/v22.0.0/lib/Service/V2/Core/AccountService.php: Recipient transfer-only capability and explicit country/entity parameters.
- https://raw.githubusercontent.com/stripe/stripe-php/v22.0.0/lib/Service/V2/Core/AccountLinkService.php: hosted Account Links v2 and upfront collection.
- https://docs.stripe.com/api/v2/core/accounts/object: authoritative Recipient configuration/requirements/closed status.
- Context7 official stripe/stripe-php documentation queried; legacy v1 examples do not override accepted Accounts v2 Recipient design.

Used Stripe best-practices skill at `/Users/florianpaumier/.codex/plugins/cache/claude-plugins-official/stripe/0.10.3/skills/stripe-best-practices/SKILL.md` and its Connect reference. Operator dashboard/responsibility/country configuration is not guessed. No provider account creation was attempted.

## Observed TDD

Commands use `docker compose --env-file .env.docker exec -e APP_ENV=test php vendor/bin/phpunit <file>`.

- SellerReadinessTest: initial missing-class error; active transfer scenario green (1 test, 3 assertions). Added inactive/pending/unrequested/unknown denial scenario: observed true-vs-false failure, then green (2 tests, 15 assertions). Active capability with outstanding requirements: observed ready-vs-pending failure, then green (3 tests, 18 assertions).
- SellerIdentityTest: missing-class error, then explicit actual-country/known-email green (1 test, 2 assertions). Missing/malformed country and missing known email initially failed to throw; then green (4 tests, 5 assertions). No implicit France default.
- SellerOnboardingTest: an initial missing controlled-provider fixture was corrected without claiming behavioral red. Then real isolated PostgreSQL migration setup passed and missing SellerOnboarding produced the genuine public-use-case red. Initial implementation subsequently reached missing journal table (42P01); this is an infrastructure dependency, not a green run or another behavior red. Parent owns the required forward migration.

## Pending

Complete first integration green after durable journal migration, then observe separate red/green behaviors for authoritative identity scope, ambiguous provider creation, same-key retries within retention, leases/fencing, outdated operation reconciliation, ready-account link avoidance, returned browser remaining pending, readiness downgrade and adapter payload/configuration validation. Verify SDK through controlled transport and actual Stripe test-mode only once operator test configuration is available. Controlled providers alone cannot establish ST-01–04 provider acceptance. No full coverage/static/mutation checkpoint is claimed for this in-progress slice.
