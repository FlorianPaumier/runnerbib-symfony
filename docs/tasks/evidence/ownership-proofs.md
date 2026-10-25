# Imported ownership evidence — 2026-10-10

The `SubmitOwnershipProof::imported` application seam now allows an active, verified account to produce an approved version-1 proof when its authoritative normalized email matches the imported original holder for a participating race. It does not claim ownership or publish a listing. Those transitions retain their existing independent gates.

`DbalOwnershipProofStore` locks the race before entitlement/account/profile, persists approval and its authority audit in one PostgreSQL transaction, and returns false for an existing proof identifier. Wrong holders, unverified accounts, missing entitlements and nonparticipating races receive no proof. The audit records the matching mechanism and proof version without the original holder's email. An audit storage failure rolls back approval.

Observed commands, using `docker compose --env-file .env.docker exec -T -e APP_ENV=test php`:

- PHPUnit `tests/Inventory/EntitlementPersistenceTest.php --filter MatchingVerifiedHolder`: red, missing `DbalOwnershipProofStore`; after implementation and correcting the query to select the proof under test among pre-existing fixtures: green, 1 test / 7 assertions.
- PHPUnit `--filter ImportProofRetry`: red, duplicate primary key on retry; after refusing an existing proof identifier: green together with the holder case, 2 tests / 13 assertions.
- PHPUnit `tests/Inventory`: green, 22 tests / 80 assertions after the missing-entitlement check and race-first lock-order refactor.
- PHPStan level 10 for Application/Infrastructure Inventory and Inventory tests: no errors.
- PHP CS Fixer for the three new source files and the updated test: no changes required.
- Existing Registration/Races/Selling regression: green, 115 tests / 356 assertions.

The first regression invocation without explicit process `APP_ENV=test` failed during setup because the running container exports `APP_ENV=dev`. That failure was not a business red; retrying with the test environment fixed setup.

SELL-02 remains open: original purchase-document upload/moderation, private reviewer evidence and modified proof revalidation need their own slices. No document-format, retention or import allowlist policy was inferred from unresolved decisions. No credentials, production data or external providers were used.

## Pending document rejection — 2026-10-11

`ReviewOwnershipProof::reject` supports only a pending document proof at its exact current version. The reviewer must be an active verified administrator or super-admin, read authoritatively from PostgreSQL. A normalized nonblank private reason is required. Race/proof/reviewer locks and decision/version/reviewer audit updates share one transaction. Ordinary account ownership, unverified/suspended administrator status, stale version, missing proof and an already reviewed decision do not authorize the operation. It does not revoke an approved proof or alter a live listing, checkout or payment.

Observed red: public review facade missing. Initial implementation green: 1 test / 6 assertions. Extended real PostgreSQL Inventory suite green: 25 tests / 105 assertions before adding the explicit verified submitter-without-admin-role denial. Real audit failure rolls the decision and reviewer fields back. Focused PHPStan level 10 passes.

Document upload, quarantine/scanning, private-object byte access, authenticity checklist, retention and approval remain unimplemented/gated. No physical document was inspected and no moderation approval is claimed. The existing object metadata table alone does not prove an uploaded document is safe or authentic.

### Private document quarantine — 2026-10-11

Observed public upload red: `APP_ENV=test vendor/bin/phpunit tests/Inventory/EntitlementPersistenceTest.php --filter DocumentUploadQuarantines` failed because `LocalProofQuarantine` did not exist. Initial implementation passed with 1 test / 12 assertions; expanded real PostgreSQL and filesystem checks passed with 1 test / 19 assertions. Inventory regression before the final authority-race extension passed with 28 tests / 131 assertions. Focused PHPStan level 10 passed.

`UploadDocumentProof` verifies active, verified uploader authority and published nonparticipating race scope before reading a server-controlled temporary upload. `LocalProofQuarantine` requires explicit private absolute directory, allowed MIME types and byte limit; missing policy fails closed. It bounds actual bytes, inspects content with fileinfo, rejects declared MIME mismatch, writes an opaque exclusive object outside public with mode 0600, and records server-calculated SHA-256 metadata. Original upload names and file contents never enter audit metadata.

The persistence transaction locks the race, account and matching entitlement, rechecks authority after the file write, and creates only private-object metadata and a pending document proof alongside its audit. Existing imported or another account's entitlement is protected. No ownership, approval, publication or availability event is created. Audit failure and an account suspended between physical write and commit both leave no new metadata or new blob; unauthorized callers are denied before source access. Existing object collisions preserve the original file.

Runtime directory, approved MIME/byte policy, malware scanner, reviewer approval flow, private read/download authorization and accepted retention durations remain pending. These quarantined bytes are untrusted; PDF MIME inspection is neither content validation nor a malware scan. A process crash between filesystem write and database commit can leave a private orphan requiring reconciliation; no retention duration or deletion approval has been invented.

### Authorized reviewer download — 2026-10-11

Observed public red: the real upload/download test failed because `DownloadOwnershipProof` did not exist. Initial implementation then passed with 1 test / 23 assertions. Expanded actual PostgreSQL roles and file integrity checks passed with 2 tests / 39 assertions; Inventory passed with 34 tests / 175 assertions before the later missing-object cleanup case. Focused PHPStan level 10 passed.

`DownloadOwnershipProof::execute(reviewerId, proofId)` returns a `ProofDownload` containing bytes and an opaque proof-ID attachment filename, or null when unauthorized/missing/purged. `OwnershipProofStore::documentForReview` requires active verified admin/super-admin authority and an unpurged object categorized as ownership proof. The use case checks the same authorization and metadata again after reading; suspension during the real file read returns no bytes. `PrivateProofReader` is implemented by the existing local quarantine adapter, with configured read limit, canonical private directory, strict opaque key, symlink refusal and persisted length/SHA-256 integrity checks.

Malformed roles `[null]`, `[123]` and an array containing a role object are legally representable in the existing PostgreSQL JSON-array constraint, and fail closed before document access. Rejection with malformed roles leaves decision and audit unchanged. JSON-object roles are rejected by the existing database constraint; no mocked driver rows or altered constraints were used to reach that impossible malformed-list guard.

The adapter now rejects root directory configuration at the first destination guard. Earlier canonical-directory validation already rejected `/` before any permissions change; the explicit guard makes that invariant direct. Existing dedicated directories are secured to 0700. Real tests cover public and symlink directory refusal, a file obstructing directory creation, missing/symlink/tampered/oversized read objects and preservation of unrelated objects after a directory replacement. Missing-object cleanup initially produced a PHP warning (observed failing check); conversion to the intended explicit cleanup exception passed with 1 test / 2 assertions and no warning.

This is privileged retrieval of untrusted quarantine bytes, not a scan or authenticity approval. HTTP responses must use attachment disposition, `application/octet-stream`, `Cache-Control: no-store` and `X-Content-Type-Options: nosniff`; HTTP integration belongs to Presentation. Configuration must use a dedicated application-controlled private directory. Directory identity checks defend against accidental/reconfigured storage replacement, not an adversarial operating-system user controlling the filesystem.

### Safe filesystem refusal verification — 2026-10-11

Added verification of existing public adapter behavior with actual temporary files: a private directory under an existing regular temporary file cannot be created; a symlink upload source is rejected without touching its real bytes; a mismatched discard DTO key cannot delete an unrelated private object; and a removed configured directory cannot redirect cleanup or remove objects preserved elsewhere. Existing tests also cover configured directory symlink canonical refusal. No system-directory permissions, forced short writes, mocked drivers or infrastructure source changes were involved.

Observed commands: `docker compose --env-file .env.docker exec -T -e APP_ENV=test php vendor/bin/phpunit tests/Inventory/PrivateProofStorageTest.php` passed with 11 tests / 47 assertions; the whole `tests/Inventory` suite passed with 38 tests / 192 assertions. `APP_ENV=test` was set explicitly in the PHP process. Focused PHPStan level 10 and formatter checks passed. These were verification tests of existing behavior, not claimed new red-green implementations.

### Actual PDO scalar typing verification — 2026-10-12

Using the shared isolated real PostgreSQL `stringifiedDatabase()` connection (`PDO::ATTR_STRINGIFY_FETCHES=true`), public seller inventory and proof download tests passed with 2 tests / 19 assertions. Native bool/bigint metadata guards fail closed under actual driver configuration. Private proof metadata rejection occurs before the unconfigured real blob adapter can be read. Existing proof decision, private object count, entitlement owner, listing price and audit remain unchanged; no provider state was created. These are green verification tests, not fabricated driver rows or claimed new behavioral red. Imported UUID/varchar/JSON guards do not gain reachable failures from scalar stringification and were not forced.
