# Race schema contract

Finalized design baseline, 2026-10-08. Supersedes the `races` row and separate `race_registration_fields` proposal in the original schema inventory. No migration has been applied. One race table within the 40-table candidate; value objects do not require tables.

## Identity and scope

A Race is one edition and one entry category/distance, the scope of race + bib uniqueness and one-current-bib-per-buyer rules. Repeated annual editions have different UUIDs. Do not make name/date globally unique: legitimate events can share them. Multiple distances with separate entry pools use separate race records initially; a festival/series parent is deferred.

Organization ownership, organizer participation and public visibility are independent. Nonparticipating races may have no organization; discovery must never require an inner organization join. A participating race requires an organization. Linking an organization does not itself confer participation or management authorization.

## Columns

Unless marked optional, columns are NOT NULL. UUIDs are assigned by the application; timestamps are UTC instants.

| Column | PostgreSQL type/default | Contract |
|---|---|---|
| id | uuid, primary key | Stable race/edition/category identity |
| organization_id | uuid, optional | FK organizations(id), ON DELETE RESTRICT |
| name | varchar(255) | Trimmed nonempty public title |
| starts_at | timestamptz | Event start instant; never assume legacy timestamps were UTC |
| timezone | varchar(64) | Valid IANA identifier, explicitly supplied; no guessed migration default |
| sport_code | varchar(64) | Nonempty stable code, e.g. running; accepted vocabulary validated in Domain |
| distance_metres | integer | Strictly positive; API may present kilometres |
| street | varchar(255), optional | Public event address, never a runner's private address |
| city | varchar(255) | Nonempty public location label |
| region | varchar(255), optional | Public regional label |
| postal_code | varchar(16), optional | Text preserves leading zeros |
| country_code | varchar(2) | Uppercase ISO country code; launch validation restricts supported market |
| latitude | numeric(9,6), optional | -90 to 90 |
| longitude | numeric(9,6), optional | -180 to 180; both coordinates present or both absent |
| description | text, optional | Public description; sanitize at input/rendering boundaries |
| image_url | text, optional | Public asset reference; validated URL, no private proof object |
| official_url | text, optional | Validated public HTTP(S) event URL |
| publication_state | varchar(16), default draft | draft, published, archived |
| participation | varchar(24), default nonparticipating | participating, nonparticipating |
| resale_deadline | timestamptz, optional | Explicit deadline; NULL means unspecified, not unlimited resale |
| min_price_cents | bigint, optional | Positive optional participating-organizer bound |
| max_price_cents | bigint, optional | Positive optional participating-organizer bound |
| registration_schema_version | integer, optional | Positive when a schema exists |
| registration_schema | jsonb, optional | Bounded versioned field-definition object; contains definitions, never answers |
| created_at | timestamptz | Creation instant |
| updated_at | timestamptz | Last change instant, >= created_at |
| version | bigint, default 1 | Positive optimistic concurrency counter |

EUR is the launch currency; bounds use the same EUR context as listing and checkout amounts. No configurable per-race currency or floating-point prices. Creator identity/history belongs to the administrative audit, not a mandatory FK that blocks account redaction.

## Database constraints and application validation

Database CHECKs enforce enum values, positive distance/version/bounds, trimmed nonempty name/city/sport/timezone, uppercase two-letter country shape, paired coordinates and ranges, timestamp ordering and min <= max when both exist. Participation implies organization_id IS NOT NULL; nonparticipating implies both price bounds IS NULL. Registration version and JSON are either both absent or both present; present JSON must be an object and its version positive. Optional text fields normalize blank strings to NULL.

The Domain validates real IANA/ISO/sport values, URLs, schema keys/types and payload limits; syntax-only CHECKs do not prove membership in those registries. Do not add a CHECK using current time. A resale deadline need not be constrained relative to event start until the business rule is agreed.

Published discovery requires complete core columns. No invented global resale cutoff: discovery is allowed with an unspecified deadline, but new sale publication/checkout must not interpret NULL as permission. Until an explicit deadline or an accepted fallback policy exists, return a configuration-required outcome. Historical committed checkout terms remain unchanged by later race edits.

Race metadata updates use `WHERE id = :id AND version = :expected_version`, increment version and append relevant audit evidence in the same transaction. Publication/membership snapshot coordination locks the race row per the shared lock order. Participation, organization and registration-schema changes must check dependent inventory/checkouts; never silently reinterpret existing proof or committed snapshots.

Registration definitions can change prospectively with an incremented schema version. Checkout items retain the exact selected definition/version and answers. The race stores only the current definition; audited changes and immutable checkout snapshots preserve history. Exact additional fields remain a product decision, not guessed required columns.

Archive races instead of cascading deletion. Entitlement, listing, purchase, waitlist and financial-context references must restrict race deletion. Authorized personal-field redaction does not delete the public race or transaction identity.

## Search contract and initial indexes

Public discovery selects published races and returns a public DTO. Supported design filters: start-date interval, sport, country/city, distance range and name text. Default order is starts_at ASC, id ASC; cursor uses both fields, page size defaults to 20 and is capped at 100. These pagination numbers are implementation defaults, not a latency SLA. Optional organization data uses LEFT JOIN. Listing availability is computed from current listing/protection state, never a stale stored race count.

Initial indexes beyond the primary key:

```sql
CREATE INDEX ix_races_public_start
    ON races (starts_at, id) WHERE publication_state = 'published';
CREATE INDEX ix_races_public_sport_start
    ON races (sport_code, starts_at, id) WHERE publication_state = 'published';
CREATE INDEX ix_races_organization_start
    ON races (organization_id, starts_at, id) WHERE organization_id IS NOT NULL;
```

For name substring search, the discovery migration also enables `pg_trgm` and adds a partial GIN index on `name gin_trgm_ops` for published rows. Text matching is case-insensitive; accent folding and typo ranking are deferred, not implied by trigram indexing. Bind all filters and use an allowlisted sort contract. Country/city/distance indexes are added only if representative EXPLAIN plans show their benefit; this schema supports them without extra tables. No PostGIS or external search service initially.

## Legacy mapping

Map Race.id/name/date/type/location/description/image/url to the new columns. Legacy `isEnabled` maps to publication_state only, not participation. Establish participation from authoritative organizer workflow, not organization FK presence. Convert documented kilometre distances to integer metres with explicit rounding and review invalid/outlier rows; reject missing/ambiguous values rather than inventing data. Convert legacy coordinate Vector values only after verifying axis order. Resolve timestamp timezone and country labels explicitly during import. Preserve known existing resale settings where found; never manufacture missing bounds/deadlines. Existing recurring editions need identity review before importing bibs.

## Next implementation slice

Use separate pure Domain race/value objects and DBAL mapping; Presentation invokes Application discovery through a read port. Before implementation, agree the additional test seams beyond the currently approved health/database readiness boundaries: race HTTP discovery and real PostgreSQL race persistence.

Then perform red → green → refactor for creation/constraints and discovery. Verify nonparticipating discovery without an organization, disabled/archived exclusion, tied-date cursor pagination, exact distance conversion, price-bound rejection, invalid registration shape and stale-version updates. Verify migration apply/replay on isolated databases and no cascade deletion of dependent history. Measure EXPLAIN (ANALYZE, BUFFERS) with representative data. Apply accepted coverage and quality gates; this document makes no runtime or performance claim.

Sources: [accepted backend requirements](../../../specifications/backend/specification.md), [business rules](../../../specifications/product-rules.md), legacy `api-bib/src/Entity/Race.php`, `Address.php`, `Repository/RaceRepository.php` and `api-bib/docs/data-models/README.md`; [PostgreSQL constraints](https://www.postgresql.org/docs/17/ddl-constraints.html), [trigram indexing](https://www.postgresql.org/docs/17/pgtrgm.html).
