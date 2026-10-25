# Configured CSV preview — 2026-10-10

`PreviewEligibilityImport::parse` uses an explicit header mapping (`bib`, `email`, optional `name` mapped to configured CSV header names), byte limit and record limit. Without all required policy configuration it fails closed. No production CSV header names or approved size limits are inferred from open DEC-02.

The PHP CSV parser runs against memory-only `SplTempFileObject(-1)`. Preview supports UTF-8/BOM, CRLF and quoted multiline fields. It retains textual bib leading zeros, normalizes holder email, reports invalid columns/bib/email and later duplicate bibs without mutating anything. Row numbers count CSV records including the header, rather than physical lines inside quoted multiline cells. Returned holder data belongs to the private organizer import workflow and must not be exposed by a public controller.

Observed runs (`docker compose --env-file .env.docker exec -T -e APP_ENV=test php vendor/bin/phpunit`):

- `tests/Imports`: red, missing PreviewEligibilityImport; implementation green, 1 test / 1 assertion.
- Boundary suite `tests/Imports`: green, 8 tests / 8 assertions. Missing/duplicate headers, byte/row limits, invalid UTF-8, missing policy, invalid rows and optional name covered.
- Memory-only resource refactor exposed an extra end-of-file empty row in SPL; fixed its EOF handling and final `tests/Imports tests/Registration` green, 36 tests / 77 assertions.
- Numeric JSON property key regression in RegistrationSchema: red TypeError from trim on integer key; casting the JSON property key for whitespace validation fixed it. Registration green, 28 tests / 69 assertions.
- Focused PHPStan level 10 clean, CS Fixer no changes.

INV-02 remains open: authorized persisted imports, database-existing duplicate handling, immutable stored row results, explicit update allowlist and screens remain separate slices. No table, dependency, provider call or runtime policy default was added.

## Authorized persistence and selected updates — verified 2026-10-11

`ExecuteEligibilityImport` now composes configured preview, `OrganizationScope` and an owning transaction. It locks race, organization, actor and current membership before authorization and persistence. Only an active verified member of the approved organizer can import a participating race. Missing runtime policy still fails closed.

Default imports insert unclaimed entitlements using existing tables, report invalid/duplicate rows, preserve existing records and never create accounts. Replaying the same completed import identifier returns its stored outcomes without another audit or changing the original holder. Another authorized member cannot reuse the identifier. Persisted outcomes contain record numbers, statuses, bib identifiers and validation reasons; email/name values remain in the separate private entitlement fields. They are not legal anonymization guarantees.

Update mode requires explicitly selected, configured approved fields. Its currently implemented registration fields are original holder name and email; ownership/payment fields cannot enter the allowlist. Claimed entries, entries with any listing record and document-based evidence are protected. Allowed updates increment entitlement version and invalidate previous imported-proof approval/review/version. Default skip mode never does this.

Observed red→green: missing execution facade; duplicate-ID retry incorrectly inserted another import; selected update returned duplicate rather than updated; prior proof remained approved at old version; direct adapter writes without an owning transaction retained data before audit failure; document-based holder data was incorrectly overwritten; malformed legacy status was incorrectly replayed. Each failing test was run before its correction. Authorization, injected real audit failure, legacy JSON guards and concurrent imports also passed. Two child PostgreSQL connections were observed blocked on the shared race lock; release produced one imported entitlement and one duplicate, preserving the winner's original holder.

Forward migration `Version20261010000100` freezes completed import outcomes/provenance against rewrite/deletion. A real pre-migration rewrite test failed as intended before adding the trigger. Doctrine command rollback allows the old behavior and replay restores protection; no-op updates remain accepted. No accepted personal-data retention/redaction policy was fabricated; deletion policy remains an open release decision. Physical private-object purge can update its own separate metadata without rewriting completed import outcomes.

Final command: `docker compose --env-file .env.docker exec -T -e APP_ENV=test php vendor/bin/phpunit tests/Imports tests/Registration tests/Inventory tests/Selling` — **107 tests / 423 assertions green**. Focused PHPStan level 10 passes. Earlier focused Xdebug measured 100% of Import and Registration Domain/Application statements; final whole-project gates belong to the orchestrator's current snapshot.

INV-02 remains open for operator-approved runtime contract and screens. Neither forward migration was applied to the development database by this specialist; only isolated test databases were migrated.

### French organizer CSV screen — 2026-10-12

New route: `/organisations/courses/{raceId}/import`. `OrganizerImportController` uses constructor-injected CurrentIdentity, OrganizationScope, PreviewEligibilityImport and ExecuteEligibilityImport. Nullable presentation `$maximumBytes` and `$mutableFields` must be bound to the same approved policies as those use cases; defaults remain fail-closed. Root owns shared bindings. No approved header names, size limits or update allowlist were invented for runtime; tests use explicit fixture policies only.

Observed HTTP red: missing route returned 404 where the private unavailable-policy page was expected. That step passed with 1 test / 9 assertions. The initial configured preview fixture hit a private-service replacement setup error, not a behavioral red; moving the actual use cases into controller constructor injection made configured HTTP fixtures explicit without making runtime services public. Against the GET-only constructor seam, the preview/commit test observed 503 versus expected 200, then passed after implementation. French count summaries had an observed missing-text red then green; malformed request array shapes initially returned 503 and were corrected to 400 with their test green.

Private authorization runs before policy checks or upload access: active verified membership and approved organization/race scope are required. POST adds CSRF and UUID syntax checks. Upload error codes, actual temporary file, symlink refusal and bounded read precede the existing UTF-8/header/row parser. Preview remains informational. Users choose the file again to commit; execution reparses fresh bytes and its persisted outcomes determine imported/updated/protected/duplicate/invalid counts. A completed import UUID replays immutable actor/race-scoped results without a second audit or mutation; a new-import link creates a fresh server UUID. Preview/retry preserve the existing hidden UUID.

No raw CSV is stored in session, flash or a new table. Responses render row/bib/status and translated generic reasons, not imported emails, holder names, file names or source contents. Private responses use no-store, nosniff and no-referrer. Update controls are shown only for explicitly configured mutable fields; actual execution revalidates the existing allowlist. A verified import proof and real claim test demonstrates that later UI updates cannot overwrite claimed ownership or original-holder fields. Another organization's actor is denied before preview; nonparticipating execution refuses before creating import metadata.

Focused HTTP checkpoint: 7 tests / 126 assertions passed, with PHPStan level 10 and Twig syntax checks passing; later fresh-upload revalidation assertions and combined regression are reported separately. Invalid CSRF, UUID/action/mode/field shapes, upload error codes, byte limits, UTF-8 and headers create no import, entitlement or audit rows. Runtime import decisions remain pending DEC-02, so this screen is not recorded as full INV-02/SELL-05 acceptance.
