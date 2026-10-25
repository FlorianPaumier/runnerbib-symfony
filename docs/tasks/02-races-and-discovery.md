# Race models, persistence and discovery

Status: planned, 2026-10-08. First business delivery slice. Follow the finalized race contract; use fixtures for anonymous discovery before account management is ready.

Use the [task index and completion rules](README.md). Dependencies reference task IDs, not automatic authorization to execute another task.

## RACE-01 — Race domain and persistence

- [ ] Complete this task and link executed evidence.

Dependencies: BASE-01.

Deliver: Implement framework-free Race/identity/distance/location values and DBAL mapping from the finalized schema.

Acceptance: Round-trip UTC instant/IANA timezone, exact metre distance, optional organization and version checks through the agreed application/persistence seam.

## RACE-02 — Race creation and edits

- [ ] Complete this task and link executed evidence.

Dependencies: RACE-01, ORG-02.

Deliver: Implement scoped create/update/archive commands with optimistic concurrency and audit; separate participation from publication.

Acceptance: Managers cannot change another organization race; nonparticipating races need no organization; stale writes fail; no cascade deletes of history.

## RACE-03 — Public race discovery

- [ ] Complete this task and link executed evidence.

Dependencies: RACE-01.

Deliver: Implement explicit public read DTOs, name/date/sport/location/distance filters and deterministic cursor pagination.

Acceptance: Only published races appear; same-date pagination has no duplicates/skips; missing organizers do not exclude races; no personal/provider/proof fields escape.

## RACE-04 — Race policy and registration definitions

- [ ] Complete this task and link executed evidence.

Dependencies: RACE-02, DEC-01, DEC-02.

Deliver: Implement bounds/deadline/registration-schema version changes and dependent-record guards.

Acceptance: Nonparticipating bounds stay absent; missing cutoff follows the accepted policy; edits cannot rewrite committed checkout terms or silently reinterpret proof.

## RACE-05 — Discovery query measurements

- [ ] Complete this task and link executed evidence.

Dependencies: RACE-03, DEC-08.

Deliver: Measure representative selective/broad searches with EXPLAIN ANALYZE BUFFERS; verify initial B-tree/GIN indexes and add only justified indexes.

Acceptance: Recorded query plans meet the agreed budget; bounded pagination and no per-result query loop; geography/typo features are not implied by trigram indexing.

## RACE-06 — Race discovery and detail screens

- [ ] Complete this task and link executed evidence.

Dependencies: BASE-02, RACE-03.

Deliver: Build French search/filter/results/detail screens with loading/empty/error/archive states and public organizer information.

Acceptance: Keyboard/navigation/mobile-width journeys work against real backend data and do not claim stock from stale race counters.

Sources: [product rules](../../../specifications/product-rules.md), [backend requirements](../../../specifications/backend/specification.md), [web requirements](../../../specifications/frontend/specification.md), [architecture plans](../architecture/README.md), [open decisions](../../../specifications/decisions-and-gaps.md).
