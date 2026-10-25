# Migration implementation

Implemented 2026-10-08 after baseline and architecture commits. Version20261008000100 installs the consolidated 40-table schema, plus Doctrine migration bookkeeping. It supersedes earlier statements that no migration files exist; no migration has been applied to the development application database.

The SQL files are the exact column contracts for the combined tables. Fields merged from the 64-table inventory remain on their owning records. PurchaseItem additionally carries checkout_id to enforce item/purchase checkout consistency; ownership transitions tie recipient and sequence to their exact purchase item. No chat or optional analytics tables are installed.

Current forward schema has **41 business tables**. Version20261009000200 adds `seller_onboarding_operations` because connected-account creation must have durable local identity before Stripe returns its non-null reference. Its operation parameters are immutable, private provider identity can be irreversibly redacted, and provider/scope/seller uniqueness prevents independent profile/listing creation journals. This is distinct from the positive-EUR payment-operation journal.

## Integrity

Typed foreign keys, composite context keys, partial unique indexes and row checks protect race/bib identity, current-owner uniqueness, active organization ownership, active waitlist memberships, published offers, protected checkout items, unresolved financial attempts and provider-scope identities. All business/history FKs restrict deletion. Price arithmetic uses numeric casts to avoid bigint overflow during checks.

Triggers protect committed financial/checkout/purchase/ownership/availability facts and append-only audit rows. Only selected lifecycle/projection columns remain mutable. Checkout private fields permit complete retention redaction and prohibit restoration; amounts/acknowledgments remain immutable. Publication validates the exact approved proof version and current owner. Released/consumed checkout protection cannot reactivate. Application transactions still coordinate proof changes, ownership and publication to avoid races; a publication trigger does not replace shared row locks.

Cross-row totals, complete-cart cardinality, fee calculations, seller readiness, authorizations, refund/recovery caps, retained-evidence decisions, event-snapshot cardinality and financial target-kind validation remain application responsibilities. The schema is infrastructure for those use cases, not a working marketplace. Immutable financial intents cannot be casually changed to implement unresolved refund policies.

## Operations

Run `make migration-status`, `make migration-dry-run`, then `make migrate` against the intended development database. Production first runs `php bin/console cache:clear --env=prod --no-debug` using the new image, then `php bin/console doctrine:migrations:migrate --env=prod --no-interaction` explicitly using a migration-owner connection before switching traffic. This also prevents retained runtime volumes from hiding newly installed console commands behind a stale compiled container. Do not run migrations automatically in every PHP worker startup.

Use an existing schema owner/migration role for DDL and extension installation. Provision a distinct non-owner, non-superuser runtime role via deployment secret management. As owner, run tools/database-runtime-grants.sql with psql `-v runtime_role=<existing-role>` against the intended database, then configure the runtime DATABASE_URL to that role. The script grants no schema CREATE or migration metadata writes. Local Docker still uses its development administrator account; it is not the production privilege model. Reapply/review grants after future table additions.

Rollback to `first` drops all 40 marketplace tables and their trigger functions: it destroys marketplace data. It is exercised only in uniquely named isolated test databases. pg_trgm is retained on rollback because an extension can be shared with other database objects. Future production changes should use forward migrations/backups rather than replaying this initial destructive rollback.

Do not edit an applied version. SQL statements are separated by explicit `-- statement` markers so PL/pgSQL bodies can contain semicolons without breaking execution. Migrations are transactional and all pending versions are all-or-nothing. PostgreSQL is required; no SQLite approximation is used.

## Verification and limits

Tests use actual Doctrine commands against temporary databases, then exercise SQL constraints, immutability, private redaction, retries and a two-connection checkout-protection conflict. Tables/trigger functions are installed, rolled back and replayed. Each test drops only its uniquely named database.

Coverage gates cover hand-written runtime src/ code. Migration files are outside that runtime source scope; 100% runtime coverage does not imply 100% SQL coverage. Migration PHP is included in PHPStan level 10 and formatting checks. The migration tests provide executable DDL evidence; representative search performance, authorization workflows and production deployment remain future work.
