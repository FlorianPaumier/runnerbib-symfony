# Search, privacy and reporting requirements

Saved 2026-10-08. Supplements the [40-table candidate](schema-size-review.md); proposed contracts, no migrations or performance measurements. Schema support is not proof of RGPD compliance.

## Search

- Keep commonly filtered race attributes in typed columns: start date, location identifiers, sport and distance. Keep listing race, publication state, price in cents and publication time typed. Final filters follow the discovery use case; avoid arbitrary JSON filters.
- Use B-tree indexes matching actual equality/range/sort combinations. Candidate listing indexes: `(race_id, published_at, id)` and `(race_id, price_cents, id)` restricted to published rows. Race date/location indexes depend on the selected filters. Do not create every possible combination.
- For substring or fuzzy race-name/city search, evaluate PostgreSQL `pg_trgm` with a GIN index. Full-text search is an alternative for word-based queries. No separate search engine/table is required initially.
- Return an explicit public projection; never index holder emails, proof contents or private registration snapshots for public discovery. Query current listing/protection state so sold or protected inventory is represented correctly.
- Bound page size, use deterministic sorting and cursor pagination for deep pages, and avoid one query per result. Verify representative data, selective and broad filters, concurrent changes and `EXPLAIN (ANALYZE, BUFFERS)` before claiming efficiency. Agree a latency target when implementing discovery.

## RGPD lifecycle

- Inventory personal fields across profiles, entitlements, imports, checkout/purchase snapshots, proofs, inbox/outbox payloads, notifications, logs, exports and backups. Define purpose, lawful basis, access and retention per category.
- Preserve financial amounts, operation identities and reconciliation evidence independently of deletable private fields. Combined purchase/entitlement rows must allow authorized private-field redaction without rewriting financial facts. Replacing a name with an account UUID is pseudonymisation, not guaranteed anonymisation.
- Account deletion immediately removes unsold listings from sale, revokes credentials and schedules private-data removal. Open payments/disputes can block only the supporting data whose continued retention is justified; record reason and review/expiry rather than an indefinite blanket hold.
- Provide authenticated access/export, correction, deletion and preference workflows with auditable request state and outcomes. Decide whether existing account/audit records suffice or a dedicated privacy-request table is warranted when these workflows are implemented.
- Record optional consent purpose, policy version, timestamp, source and withdrawal evidence using restricted audit records alongside current preferences. Separate newsletter, waitlist and essential transactional messages. Do not put identifying payloads into generic audit records unnecessarily.
- Enforce accepted product retention: operational logs 30 days and aggregate analytics 90 days. Evidence dispute period and financial/newsletter retention remain unresolved. Include private object deletion, temporary exports, provider boundaries and backup expiry/restoration handling in the retention design.

## Reports

- Launch product analytics remain aggregate signup, listing-publication and completed-purchase counts. No emails, proof contents, payment identifiers or per-user tracking in analytics. Retries must not increment counts twice; use committed business transitions and durable deduplication.
- Start with SQL read views or queries. If daily counters are persisted for stable historical reporting, propose one `daily_metrics` table with `(day, metric)` uniqueness and nonnegative counts: the candidate would then be 41 tables. This addition is not yet approved or implemented.
- Financial operational reports can derive sales, fees, refunds, payable balances and confirmed transfers from existing financial records. Define gross/net/refund semantics and group each fact at its own grain before joining to avoid multiplication by items or attempts. Failed/pending attempts are not confirmed money movements.
- Restrict organizer reports to their races and financial reports to authorized roles; exports inherit those restrictions. Keep aggregate product analytics separate from financial records subject to their own retention rules. Suppress or combine small groups when breakdowns could identify individuals; aggregation alone does not guarantee anonymity.
- Start without extra reporting infrastructure. Add materialized views or a reporting replica only after measured query load justifies them, documenting refresh delay and freshness requirements.

## Verification when implemented

Use TDD for filter/sort/pagination and availability correctness, access boundaries, export/redaction/retention including open cases, consent withdrawal and duplicate-event aggregation. Reconcile reports against known multi-item purchases, retries and partial refunds. Performance measurements complement the accepted coverage gates; none have been run against this unimplemented schema.

Sources: [PostgreSQL index types](https://www.postgresql.org/docs/17/indexes-types.html), [trigram indexes](https://www.postgresql.org/docs/17/pgtrgm.html), [CNIL retention](https://www.cnil.fr/fr/passer-laction/les-durees-de-conservation-des-donnees), [CNIL anonymisation](https://www.cnil.fr/fr/technologies/lanonymisation-de-donnees-personnelles).
