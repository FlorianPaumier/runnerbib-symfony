# Schema and runtime privilege review

Reviewed 2026-10-08 against the accepted backend/product specifications, migration SQL, runtime grant script and existing migration tests. This is a source review, not a production security certification. No source, migrations, tests, roles or grants were changed. The migration is already installed locally; corrections must use forward migrations where needed.

Follow-up status: the privilege-script finding below was fixed and exercised in isolated PostgreSQL fixtures; see [runtime grant evidence](runtime-grants.md). This review records the original finding, not a remaining claim about the amended script.

## Actionable finding

### P1 — Grant script does not remove pre-existing runtime schema CREATE

`tools/database-runtime-grants.sql:4-6` revokes schema privileges from `PUBLIC`, but does not revoke direct privileges from the selected runtime role. If that existing non-owner, non-superuser role already has `CREATE ON SCHEMA public`, running this script leaves it able to create tables/functions. Regranting USAGE does not replace existing CREATE privileges. This contradicts BASE-06's acceptance that runtime cannot perform DDL. This is a conditional, reproducible privilege defect; no such production role has been provisioned here.

Fix: explicitly revoke schema CREATE from the runtime role before granting USAGE; reject owner/superuser roles and audit inherited role memberships and database CREATE privileges. Verify effective privileges under the actual runtime role: allowed SELECT/INSERT/UPDATE operations, denied CREATE TABLE, denied migration metadata writes, denied trigger disable/ALTER/DROP. PostgreSQL role administration belongs to deployment, not an application migration. Do not claim a direct REVOKE removes privileges inherited from another role.

## Required implementation decisions, not confirmed schema defects

- **Retention worker privilege:** `tools/database-runtime-grants.sql:9` permits DELETE only on profiles, credentials, carts, notifications and newsletter subscriptions. Inbox/outbox/import/evidence payloads may be updated/redacted, but their rows cannot be deleted by that role. PRIV-03 must explicitly select redaction versus row cleanup for each category and provide a narrowly scoped maintenance identity if deletion is required. Never grant blanket DELETE over protected financial history or prematurely remove deduplication keys. The unresolved financial/proof retention policy prevents specifying a universal deletion interval now.
- **Append-only audit minimization:** audit reason/authority JSON and immutable financial parameters cannot be rewritten through runtime. Keep personal contact details, document contents and unbounded provider payloads outside immutable fields; references and necessary financial evidence need explicit retention policy. Checkout snapshot redaction already permits complete removal of buyer name/email/registration fields without changing money. That protection does not by itself sanitize other payload stores.
- **Outbox intent versus projection:** `migrations/sql/20261008000100-up.sql:373-379` permits runtime updates to message payload, effect identity and aggregate metadata. BASE-03 should expose only delivery/lease projections through its adapter and preserve immutable message intent. Consider a forward trigger analogous to financial intent protection after agreeing on any retention-redaction exception. Deduplication against a reused effect key must reject different intent rather than silently treat it as the same event.

## Reviewed protections and their limits

- Partial uniqueness protects one published listing/entitlement, one protected checkout item/entitlement, one current owner/race and one unresolved operation attempt. The existing two-connection checkout test exercises the principal exclusivity constraint.
- Composite FKs link listing/race/entitlement/seller, checkout item/purchase/buyer, ownership destination/sequence/purchase item, refund payment context and provider scope. They do not prove payment success, authorization, aggregate totals or financial target kind; these remain documented application responsibilities.
- Publication checks the approved exact proof version and current owner, but its query is not a shared concurrency lock. Proof revision, owner transfer and publication must use a consistent lock protocol in SELL/CHECK/SALE transactions; tests must use competing connections. Do not describe the trigger alone as race-safe authorization.
- Financial/checkout/purchase/availability intents and ownership/audit history are frozen. Mutable lifecycle states still require valid transition policies and optimistic updates/row locks. SQL enum checks are not state machines.
- Complete checkout private redaction is irreversible at the row level. Profile deletion plus account anonymization must also revoke credentials and coordinate open payments, disputes, imports, objects and payloads.

## Proposed BASE-03 public test seams

These are proposals only; no tests or implementation were created in this review. Obtain the user's agreement to these new behavior seams before writing tests.

1. **Application transaction boundary** backed by real DBAL: execute a use-case operation that writes its business fact, audit fact and outbox intent together. Observe commit or rollback through a second database connection. Failures must leave none of the three writes committed. Nested transaction policy must be explicit.
2. **Outbox dispatch use case** backed by real PostgreSQL and a recording/failing transport port: committed undelivered messages remain eligible after process failure; dispatch marks only the claimed generation; expired leases can be reclaimed; two workers cannot acknowledge each other's generation. Simulate failure after transport accepts but before local acknowledgement and show repeat dispatch is expected.
3. **Consumer delivery entry point** backed by real PostgreSQL: repeated `(consumer, effect_key)` delivery creates one database effect, dedup completion and effect commit together; handler failure permits retry. Two concurrent deliveries must yield one committed effect. Database deduplication does not guarantee exactly-once email/provider effects; those need provider correlation and reconciliation.
4. **Audit append port through a public application operation:** actor/service-principal identity and outcome are persisted with the operation; unauthorized actions cannot enter the transaction as approved actions. Existing append-only SQL tests remain separate infrastructure coverage.

Retain inward-owned ports and framework-free Application DTOs. Messenger envelopes/transport exceptions and DBAL connections remain in Infrastructure/Presentation. Keep provider network calls outside database transactions. Record observed red/green runs and run coverage, PHPStan level 10 and Deptrac once behavior exists.
