# PRIV-04 — French privacy intake and status

Local request/status controls implemented, 2026-10-13. They reuse ManagePrivacyRequests and its existing ports. No new persistence table, package or signing secret is introduced.

Authenticated GET `/compte/confidentialite` initially returned 404: observed red 1 test / 3 assertions. The access-request journey passed 1 / 22 after implementation. Current real PostgreSQL HTTP regression passes **5 tests / 101 assertions**: access/correction remain pending, stable request retries do not duplicate audit, conflicting intents fail, CSRF is checked, missing lifetime refuses before mutation, deletion suspends/revokes the account and a signed status code continues to work after the session is revoked. Forged, expired, unknown-request and foreign-owner codes fail without private data.

`/confidentialite/suivi` accepts authority only in a POST body. The user saves the displayed code manually; no authority-bearing URL or session storage is created. Pages send private/no-store and no-referrer headers. Pending financial/retention blockers are presented in French without claiming export, correction, purge or final anonymization is complete.

## Configuration and local runtime

`PRIVACY_PROGRESS_LIFETIME_SECONDS` is forwarded to PHP and inherited worker/scheduler services in both Compose configurations. Its default remains blank; positive local fixtures are not an accepted production lifetime. Production configuration test observed red: 4 tests, two failed environment subcases (missing blank/3600 values); after forwarding, all 4 tests pass. Both configurations validate with their required variables supplied. Validating production with the development env file initially failed because RUNTIME_DATABASE_URL was absent; the isolated production fixture supplied it explicitly.

Commands include `make test`, `make quality`, `make lint`, `make analyse`, `make cs-check`, `make assets`, `python3 tests/Infrastructure/production-compose.py`, Twig lint, both Compose image builds and isolated production HTTP/runtime-role smoke. Runtime fixture used a 600-second lifetime and synthetic account; no real provider or email delivery was attempted. BrowserKit verifies the server-side journeys; a complete interactive browser/release journey remains open.

## Stable verification — 2026-10-13

Source held stable: `make test` passed **745 tests / 4,429 assertions**. `make quality` passed its full test/coverage execution (**745 tests / 4,444 assertions**) but stopped at the overall coverage gate: **97.17% (2,717/2,796)** against required 98%; Domain/Application **100% (943/943)** passes. No exclusion or threshold was changed. Separate lint/container validation, PHPStan level 10, Deptrac (815 allowed, zero issues), formatting (352 files, zero changes), 24 Twig templates and Composer audit pass. Global mutation acceptance has not been demonstrated.

Both development and production PHP/web image builds passed. An isolated production Compose project migrated its fresh database through Version20261011000100 and ran using a provisioned restricted runtime role (no superuser, create DB/role, replication or bypass-RLS rights). Health/readiness/public pages returned 200; anonymous account pages redirected to login. The real privacy use case suspended a synthetic account and its body-only status code returned pending status over HTTP without exposing its email or recording the code in web access logs. The stack was stopped with its volumes preserved. No actual production deployment, commit, existing-volume reset or provider interaction occurred.
