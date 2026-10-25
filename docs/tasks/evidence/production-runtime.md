# Production runtime isolation and background processing

Executed locally2026-10-12. Cloud deployment/monitoring and production provider acceptance remain open. Existing smoke volumes were preserved; unrelated containers were untouched. Secrets were generated only into an ignored mode0600 environment file.

## Credential separation

Public boundary: actual `docker compose config --format json`. `python3 tests/Infrastructure/production-compose.py` initially failed2 tests: bootstrap-only production config succeeded and ignored the provided runtime DSN. After making `RUNTIME_DATABASE_URL` mandatory, both passed. Production PHP no longer derives its credentials from PostgreSQL bootstrap variables; development keeps isolated-test database creation capability. Explicit deployment-owner migrations/grants are separate.

A dedicated local LOGIN role with NOSUPERUSER/NOCREATEDB/NOCREATEROLE/NOREPLICATION/NOBYPASSRLS was provisioned in the existing isolated `runnerbib-execution-smoke` stack and the real `psql` grant script applied. The first harness invocation failed to forward the role variable through its shell command; corrected invocation applied successfully. This setup failure is not a TDD red. Updated onboarding grants were reapplied afterward.

Using actual restricted PHP credentials, all elevated role flags were false. CREATE TABLE, TRUNCATE accounts, UPDATE migration bookkeeping and UPDATE purchases each failed with SQLSTATE42501. Home/search/newsletter/login/liveness/readiness returned200; anonymous seller inventory redirected to login (the HTTP client followed that redirect). This does not prove every finance/privacy workflow works under the role. Permanent grant scenarios are tracked in runtime-grants.md.

## Background services: red → green → shutdown fix

A third public configuration test failed because no worker service existed. Added supervised restricted-role worker/scheduler services;3 tests passed. Actual immutable production images started both processes. The scheduler ran bounded due availability batches and outbox dispatch; the worker consumed the real PostgreSQL queue. Two synthetic technical intents exercised success and failure: an expired verification-mail intent was dispatched and marked expired without external SMTP, while a malformed intent exhausted retries and appeared as exactly1 message in `messenger:failed:show --stats`. No business record was fabricated or changed.

Actual stop observed worker exit0 but scheduler exit137: the PHP image inherits SIGQUIT. A configuration regression failed because the scheduler lacked SIGTERM. Explicit `stop_signal: SIGTERM` made3 tests pass; restarted processes both stopped with exit0 inside the30-second grace period. Synthetic technical rows were removed by their exact fixture IDs after shutdown. The stack was stopped without volume deletion.

Both development and production Docker builds succeeded after scheduler/image changes. Import configuration was exposed with refusing defaults and the7-test organizer import HTTP regression passed129 assertions. YAML/container validation passed. The Compose regression suite is3 tests; no FPM check is falsely applied to CLI processes. Queue-age/failure alerts, Cloud supervision, Redis-loss/restart scenarios, private-object backups and full coverage/mutation acceptance remain release work.
