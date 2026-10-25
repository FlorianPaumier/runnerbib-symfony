# SELL-03 local lifecycle checkpoint

2026-10-10. Draft creation, approved-proof attachment, publication, unlocked repricing and withdrawal are implemented behind Application `ListingStore`/`ManageListings`. Authenticated identity determines the seller; callers cannot submit another actor ID through the facade. Proof attachment pins the authoritative approved version without approving documents. Publication/repricing require active verified current ownership, current approved proof, published race, positive-net EUR fees, configured Stripe provider scope and reconciled ready seller state. Another scope's readiness cannot authorize publication. Missing resale deadline fails closed pending DEC-01; no unlimited period is inferred.

Mutation locks race then listing before checking live competitors and checkout protection. Availability snapshots and outbox intents use the same owning DBAL transaction. Repricing increments offer/listing versions, invalidates the earlier event and starts a new persisted one-hour deadline. Withdrawal invalidates the event and prevents historical listing reactivation. All five state-changing operations append authority/version audits atomically. A real audit-trigger failure rolls back the price, snapshot invalidation, new snapshot and outbox intent.

Observed red → green slices:

- Missing Domain eligibility class → 10 literal authorization/deadline/bounds assertions; later missing-deadline rejection failed true before correction, then 12 assertions green.
- Missing listing store → protected-item repricing denied without releasing its checkout lock.
- Unlocked repricing initially returned false → persisted new price/offer version and availability event.
- Another Stripe scope initially authorized publication → exact scope lookup rejects it.
- Missing publication/withdrawal/draft/proof-attachment methods → corresponding real PostgreSQL workflows pass.
- Missing listing audits (0 instead of 5) → full Application lifecycle produces five atomic audits.
- Archived race initially allowed repricing → Domain race-publication gate rejects it.

Latest `docker compose --env-file .env.docker exec -e APP_ENV=test php vendor/bin/phpunit tests/Selling`: **14 tests / 114 assertions green**. Two child PostgreSQL publishers were observed waiting on the held race lock before release: exactly one live listing and one availability intent survived. Focused PHPStan level 10 passed before the latest additional resource-denial tests. Earlier focused Clover showed 100% of Selling Domain/Application statements covered; final global coverage/mutation gates still required after latest edits.

Not completed: processing-payment withdrawal/cancellation orchestration (CHECK-04), proof moderation policy/production acceptance (SELL-02/DEC-03), external Stripe readiness acceptance, seller-facing HTTP screens, or full release gates. Locked withdrawal fails closed until authoritative cancellation/recovery exists. No task checkbox is marked complete from this checkpoint.

## Draft withdrawal completion — verified 2026-10-11

The owner may now withdraw an unpublished draft without provider configuration, fabricated approval or availability publication. Its withdrawal timestamp/version/audit are persisted; repeated withdrawal does not reactivate it, and an unrelated listing's protected checkout remains untouched. The observed public red was `withdraw` returning false for the owner’s draft. Removing that special rejection and applying forward migration `Version20261010000200` made the test green (1 test / 10 assertions).

The migration changes only the proof/publication-fact requirement for unpublished withdrawn drafts. Published and sold states continue requiring proof/version/publication facts; withdrawn rows still require their timestamp. Real Doctrine rollback/replay tests pass. If new withdrawn drafts do not meet the former schema, rollback refuses transactionally: the draft, new constraint and migration metadata remain intact. This is a documented rollback compatibility limit; no data was deleted to satisfy the old constraint.

Two migration tests pass with 16 assertions. Additional real lifecycle tests cover missing/unreviewed proof attachment and suspended-seller withdrawal without changing listing/snapshot/audit state. `tests/Selling` passes 21 tests / 164 assertions. Final combined Imports/Registration/Inventory/Selling regression passes 107 tests / 423 assertions; focused PHPStan level 10 passes. No impossible PostgreSQL driver rows were mocked to inflate coverage. SELL-03/SELL-02 checkboxes remain open for the outstanding acceptance listed above.

### Seller inventory management snapshot — 2026-10-11

Observed public read red: `APP_ENV=test vendor/bin/phpunit tests/Selling/SellerInventoryTest.php` failed because `SellerInventory` did not exist. Initial implementation passed with 1 test / 7 assertions. Expanded real PostgreSQL snapshot and lifecycle checks passed with 4 tests / 44 assertions.

`SellerInventory::list()` derives the seller from `CurrentIdentity`; `SellerInventoryStore` is implemented by `DbalSellerInventoryStore` with one SQL snapshot. Only active verified accounts receive rows. The single management DTO contains textual bib/race identity, current ownership flag, caller's own listing ID/version/price/state and attached proof ID/decision/current/reviewed versions. It contains no original-holder name/email, private file key, provider reference or another seller's listing/proof. Unlisted current entitlements carry null listing/proof fields. Caller-owned withdrawn history and current drafts remain separate, ordered by race ID and lexical textual bib, entitlement ID and listing ID; querying does not create audit, ownership or listing changes.

Tests verify leading zeros, exact safe projection, another account's denial, anonymous/unverified/suspended/anonymized/unknown denial, unlisted entitlements with private original-holder values excluded, and existing public withdrawal followed by draft creation preserving history and versions. The fixture's initial invented `deleted` lifecycle label was rejected by the real schema (setup error, not a behavioral red); it was corrected to the existing `anonymized` lifecycle with its required timestamp.

The SQL intentionally preserves the caller's historical listings after a future ownership transition and excludes the prior seller's listing from the new owner's current entitlement projection. That post-purchase behavior remains unverified pending SALE-01: there is currently no public completed-purchase/authoritative ownership-finalization seam, and no sale/ownership transition was fabricated to test it. No relisting, refund or ownership mutation was implemented. French management screen integration remains Presentation work.

### Indexed inventory query and fee preview — 2026-10-11

The read query now derives eligible entitlement IDs from the indexed current-owner branch UNION caller-owned listing history, then joins only those IDs and only the caller's listings/proofs. `actor AS NOT MATERIALIZED` preserves the known account equality for PostgreSQL selectivity; a materialized actor hid that useful equality from the planner. This remains one SQL snapshot.

Observed performance fixture: 5,000 unrelated unclaimed entitlements, 5,000 legal current-owner draft rows, the original fixture listing, and one other verified seller's own draft. No completed purchase or ownership transfer was fabricated. The actual SQL emitted by the public facade was captured through standard DBAL logging middleware and examined using real PostgreSQL `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)`, without disabling sequential scans. Before the new index, the owner branch used `uq_entitlement_owner_race`, while the history branch sequentially scanned `resale_listings`, returning one row and discarding 5,001. The observed red also confirmed `ix_listing_seller_entitlement` was absent.

Forward migration `Version20261011000100` adds `(seller_id, entitlement_id, id)` to existing `resale_listings`. With it, the history branch used an Index Only Scan of `ix_listing_seller_entitlement`, and the own-listing join used an Index Scan of the same index; the owner branch retained its existing ownership index. Public Doctrine rollback/replay passed with 1 test / 17 assertions: rollback removes only the index, replay restores it, all 5,002 listing rows and the caller's one-item result survive. These observations demonstrate the intended access paths in this fixture, not a production latency SLA. The development database was not migrated by this specialist.

`SellerInventoryItem::fees()` now exposes the existing exact `FeePolicy` result to Presentation without constructing domain services in a controller/template. Missing prices and positive-but-unquotable legacy prices return null. Observed missing-method red was followed by green €100 preview assertions (buyer total 10,500 cents; seller net 9,250 cents), null unlisted preview and real stored 1-cent/maximum-bigint rejection cases. Expanded focused inventory suite passed with 5 tests / 56 assertions before adding an explicit non-null quote assertion; final counts are reported separately.
