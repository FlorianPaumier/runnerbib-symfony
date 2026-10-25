# Race implementation evidence

2026-10-08–09. RACE-01/RACE-02/RACE-03 implementation is verified below; final shared quality gates remain pending. These tests did not reset or migrate the application database. Integration tests create `race_test_<random>` databases, apply the real Doctrine migration command and drop only their own database.

## Observed red → green cycles

Command prefix: `docker compose --env-file .env.docker exec -e APP_ENV=test php vendor/bin/phpunit`.

- `tests/Races/RaceTest.php`: exact 42195 metres initially failed with missing Distance; constructor implementation passed (1 test/1 assertion). Zero distance then failed to throw; positive integer guard passed (2/2).
- Race identity/location/UTC-start round trip initially failed with missing Race; minimal Race/identity/location passed (3/9). Malformed UUID then failed to throw and passed after validation (4/10).
- Blank location failed to throw then passed (5/11). Unsupported market/paired numeric/range coordinates produced five failures then passed after location guards (10/16).
- Invalid race metadata produced eleven missing-exception failures then passed after domain guards (21/27). Nonpositive schema/bounds and inverted bounds produced three failures then passed (24/30).
- `tests/Races/RacePersistenceTest.php`: real PostgreSQL full record round trip failed with missing DBAL repository; full mapper passed, including optional organizer, registration JSON, null and present optional fields (combined 25/35).
- Optimistic update failed with duplicate race primary key; versioned UPDATE and stale-version exception passed (combined 26/40).
- Published-only public discovery failed with missing DiscoverRaces; explicit public DTO and LEFT JOIN implementation passed (27/48).
- Bound filters/literal wildcard search failed with two results instead of one; bound filter SQL passed (28/57).
- Tied-date/default pagination failed with 21 records instead of 20; keyset tuple pagination passed (29/66).
- Search limits/ranges and malformed cursor produced six failures; guards and typed cursor parsing passed (35/72).
- Additional malformed cursor cases and inclusive boundary verification passed (41 tests/79 assertions).
- Published-only public detail failed with missing FindPublicRace then passed after its Application/read-port implementation (44 tests/90 assertions at that checkpoint).
- Race management observed missing ManageRaces on creation, an unauthorized runner creation that failed to throw, and an organization member wrongly denied creation; scoped create/auth implementation passed each cycle.
- Metadata update/archive initially failed with missing methods; versioned, audited commands passed. Ordinary edits initially allowed moving a race out of organization scope and changing participation; Domain guards now reject both while publication remains separate.
- Optional location/title metadata normalization, 255 French-character handling and optional address limits each produced the expected red behavior before normalization/bounded Unicode implementation passed.

## Executed checkpoint, 2026-10-09

`docker compose --env-file .env.docker exec -e APP_ENV=test -e XDEBUG_MODE=coverage php vendor/bin/phpunit tests/Races tests/Organizations --coverage-clover var/coverage/races-organizations.xml` passed **97 tests / 265 assertions**. Own Races/Organizations source has **340/340 covered statements (100%)**, including 100% Domain/Application. Focused PHPStan level 10 passed with no errors; focused PHP CS Fixer changed zero of 31 files at the final checkpoint. Migration setup processes allow 120 seconds for emulated/container startup contention.

An earlier focused mutation checkpoint generated 192 mutants and achieved 98.44% covered MSI. A subsequent only-covering-case run failed the 95% covered-MSI threshold at 92.81% with timeout/skipped cases; its surviving boundaries were inspected and strengthened. A later initial mutation suite exited 143. These earlier runs do **not** certify the current final source mutation score; the shared stable-source mutation gate remains pending. Transient unrelated container/SQL syntax errors during parallel work were setup failures, never counted as behavior reds.

Race discovery filters bind values, escape literal name-search wildcards, select explicit public fields and use a LEFT JOIN for optional organizations. Cursor ordering uses UTC starts_at plus UUID; the 21 tied-date record fixture confirms a 20-record page followed by the remaining single record. Race management checks current active/verified actor roles and approved organization membership, preserves creation time, applies optimistic versions, archives instead of deleting and appends audit in the same transaction. An audit-storage outage rolls race creation back. Public Application DTOs avoid exposing Domain objects to HTTP adapters.

RACE-04 remains open: accepted registration field definitions, deadline fallback and dependency-sensitive policy changes need their own workflow and decisions. This evidence makes no production latency, Stripe, Cloud or completed race-policy claim. Root owns the French HTTP screens and their independent HTTP evidence.
