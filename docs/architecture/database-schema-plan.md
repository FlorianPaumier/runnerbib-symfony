# PostgreSQL schema plan

Status: proposed physical design, saved 2026-10-08. Based on the accepted [model architecture](domain-model-plan.md), [quality plan](quality-plan.md), [logical backend model](../../../specifications/backend/data-model.md), [product rules](../../../specifications/product-rules.md) and [transaction requirements](../../../specifications/backend/implementation-plan.md). This document contains no executed DDL or applied migrations.

## Size review

This original inventory contains **64 tables** and is exhaustive reference material, not the launch migration baseline. See the [comparison and simplification review](schema-size-review.md): an initial consolidation yields a **40-table full-scope candidate**, with tables introduced only when their use cases require them. That candidate remains a recommendation; detailed columns/constraints below must be revised before implementing combined tables.

## 1. Storage conventions

Use PostgreSQL 17, one database and initially the public schema with explicit snake_case table names. Modules own their writes through DBAL adapters; shared transactions remain possible. Database schema namespaces and separate databases are unnecessary for the launch model.

- Aggregate IDs: application-generated UUID stored as uuid. Child/junction tables use UUID or composite keys as shown. UUID generation format is selected at implementation; public identifiers remain opaque.
- Instants: timestamptz, application/database connections in UTC. Persist explicit deadlines; the daily seller run also stores Europe/Paris local run_date and scheduled_at. Date-only values use date. A race's event instant and IANA timezone are separate fields.
- Money: bigint integer cents, currency text checked to EUR, exact arithmetic in Domain. No floating-point prices. Set safe upper bounds in the Money value object before enabling untrusted amounts.
- Lifecycle labels: text with named CHECK constraints, mirrored by domain enums. Values below are proposals; do not combine listing, checkout, payment, official registration and transfer states.
- Required fields are NOT NULL; `?` below means nullable. Use explicit CHECK constraints because a nullable expression can otherwise pass a check without being true.
- Mutable aggregates carry created_at, updated_at and version integer >=1. Immutable snapshots/events use created_at or occurred_at and no generic updated_at. Do not add all common columns blindly to every table.
- JSONB is reserved for versioned variable registration fields, sanitized event payloads and immutable external-operation parameters. Foreign keys, cents, ownership, eligibility and searchable states remain typed columns.
- Retain stable account IDs as anonymized tombstones. Personal data lives in separately controlled private tables; foreign-key deletion never cascades through financial/history tables.
- Operational history is append-only where required. Repository write restrictions alone are not immutability enforcement: migrations must define appropriate database grants and targeted triggers for immutable snapshots/journal parameters. A dedicated migration role owns DDL.

Foreign keys use NO ACTION/RESTRICT for business/history records. Cascades are limited to disposable children such as cart items or credentials when an authorized purge actually deletes their parent. Anonymization is an explicit workflow, not deletion of the account row.

## 2. Identity and organization tables

| Table | Main columns | Keys, checks and indexes |
|---|---|---|
| accounts | id, lifecycle_state, verified_at?, terms_version, terms_accepted_at, anonymized_at?, version | PK id; allowed active/suspended/anonymized states; anonymization timestamp consistent with state |
| account_private_profiles | account_id, email, email_normalized, display_name?, registration_profile jsonb | PK/FK account; unique email_normalized; separate controlled removal/anonymization; private by default |
| authentication_identities | id, account_id, provider, provider_subject?, password_hash? | Unique(provider, provider_subject) for external identity; one password identity/account; checks distinguish password and Google credentials; never plaintext passwords |
| account_roles | account_id, role | Composite PK; explicit allowed runner/admin/super_admin roles; owner/manager roles belong to organization membership |
| account_credentials | id, account_id, purpose, token_hash, expires_at, consumed_at? | Unique token_hash; verification/reset purposes; expiry index; no raw token persisted |
| organizations | id, name, approval_state, approved_by?, approved_at?, version | Approval-state check; FK reviewer account; owner management is application authorization |
| organization_memberships | id, organization_id, account_id, role, joined_at, ended_at? | Partial unique account_id where ended_at IS NULL; partial unique organization/account; owner/manager values; partial unique organization_id for one active owner |

Owner transfer atomically ends the previous owner membership/role and establishes its replacement under the organization row lock. The database's unique-owner constraint guarantees at most one owner; the application guarantees an approved operative organization has an owner. The one-active-organization constraint follows the accepted initial scope. Keep historical memberships rather than overwriting their identity.

Sessions remain a transport selection; do not create a speculative session table before choosing Symfony session storage and mobile authentication.

## 3. Races, original registrations and inventory

| Table | Main columns | Keys, checks and indexes |
|---|---|---|
| races | id, organization_id?, name, starts_at, timezone, participation, resale_deadline?, min_price_cents?, max_price_cents?, version | Organization FK optional; participation check; positive optional bounds and min<=max; participating requires organization; indexes starts_at and organization_id |
| race_registration_fields | id, race_id, field_key, schema_version, field_type, required, definition jsonb | Unique(race_id, schema_version, field_key); supported types constrained; versioned rather than rewriting historical checkout requirements |
| eligibility_imports | id, race_id, actor_id, mode, private_object_id?, state, started_at, completed_at?, summary jsonb | FK actor/race; skip-invalid/update modes; organization-scope validation; summary counts reconcile with row outcomes |
| eligibility_import_rows | import_id, row_number, result, original_registration_id?, error_code?, allowed_update_snapshot jsonb | PK(import_id,row_number); invalid/duplicate/created/updated outcomes; never mutate ownership/payment via import |
| original_registrations | id, race_id, bib_number, source_kind, import_id?, private_record_id?, version | Unique(race_id,bib_number); bib_number text preserves leading zeros; imported/document-verified source kinds; private personal data separated |
| original_registration_private_data | id, holder_name?, original_runner_email?, allowed_fields jsonb | Private retention category; originals need not have platform accounts; no public serialization |
| bib_entitlements | id, original_registration_id, race_id, bib_number, current_owner_account_id?, version | Unique original_registration_id; unique(race_id,bib_number); composite FK ties original registration/race/bib; partial unique(current_owner_account_id,race_id) when owner is non-null |
| ownership_transitions | id, entitlement_id, sequence_no, from_account_id?, to_account_id, kind, purchase_item_id?, claim_proof_id?, occurred_at, effect_key | Unique(entitlement_id,sequence_no), effect_key and purchase_item_id when present; verified-claim/purchase kinds; ownership restoration not implemented until policy is decided |

A null current_owner_account_id means the entitlement still belongs to the original external holder represented by its required original registration. Verified claiming binds that holder to an account; it does not fabricate an account from an import. After a completed purchase the buyer is the required platform owner. The original holder record remains available under its retention rules independently of current ownership.

Original registration may be established from an import or reviewed organizer-issued document. A draft proof/upload may precede creation of the canonical original-registration/entitlement rows; draft references are not purchaseable inventory. Bib normalization must be decided explicitly before uniqueness enforcement; do not coerce bib numbers into integers or merge identifiers through guessed case/whitespace rules.

Nullable resale_deadline preserves the unresolved default policy. A null is not automatic permission for unlimited resale: application publication/checkout must follow the agreed handling before launch.

## 4. Proof, listings and availability

| Table | Main columns | Keys, checks and indexes |
|---|---|---|
| private_objects | id, object_key, category, mime_type, size_bytes, checksum, uploaded_by?, created_at, delete_after?, purged_at? | Unique opaque storage key; no public URL; nonnegative size; category/retention metadata; protected access |
| ownership_proofs | id, submitted_by, race_id, proposed_bib_number, entitlement_id?, source_kind, original_registration_id?, private_object_id?, submitted_at | Imported eligibility or original document; source-specific required reference checks; drafts may be unbound; no raw evidence in logs |
| proof_reviews | id, proof_id, decision, reviewer_id, reason?, reviewed_at | Append-only decision history; approved/rejected; account FK; approval references the precise proof version |
| resale_listings | id, race_id, entitlement_id?, seller_id, proof_id?, approved_review_id?, price_cents, currency, state, offer_version, published_at?, withdrawn_at?, sold_at?, version | Positive price; live listing requires canonical entitlement/publication time; partial unique entitlement_id where state='published'; state/timestamp checks; FK approved review tied to proof |
| race_waitlist_memberships | id, race_id, account_id, joined_at, ended_at?, email_enabled, in_app_enabled | Partial unique(race_id,account_id) where ended_at IS NULL; index race_id/joined_at for snapshot; preferences do not redefine membership |
| race_availability_guards | race_id, version | PK/FK race; stable row locked by publication and membership join/leave |
| availability_events | id, listing_id, race_id, offer_version, price_cents, currency, available_at, priority_ends_at, recipient_count, invalidated_at?, invalidation_reason? | Unique(listing_id,offer_version); recipient_count>=0; priority deadline is available_at plus 3600 elapsed seconds; FK listing/race context |
| availability_recipients | event_id, account_id, membership_id, snapshotted_at | PK(event_id,account_id); reference membership belonging to same account/race; immutable even after membership leaves |

Maintain listing/race/entitlement consistency with composite keys where repeated context is stored. A new unlocked price creates a new offer_version and availability_event, invalidating the old one. Seller identity edits require revalidation. Draft listing price must be positive once persisted as a priced draft; an unpriced UI draft is not a purchaseable record.

Access is derived: active event plus nonempty snapshot plus authorization time before priority_ends_at means snapshot-only; empty snapshot is public immediately; after the deadline access is public. Background expiry does not control this decision. Invalidation makes the offer unavailable. Delayed notification never changes deadlines or snapshot membership.

The publication transaction fills snapshot rows and records their count while holding the race guard; a count check across rows belongs to the transaction, not a PostgreSQL row CHECK. Freeze snapshot rows after creation using database write protection. Offer prices are snapshotted on availability_events. checkout_items reference the immutable event/version, not a foreign key to the mutable listing offer_version; repricing must not break historical checkout references. Current seller ownership and readiness are rechecked when publishing and authorizing checkout, rather than relying solely on an old FK/proof approval.

## 5. Cart, checkout and completed purchases

| Table | Main columns | Keys, checks and indexes |
|---|---|---|
| carts | id, buyer_id, version | Unique buyer_id; a cart grants no lock or price guarantee |
| cart_items | cart_id, listing_id, added_at | PK(cart_id,listing_id); no quantity, reserved_until or guaranteed amount |
| checkouts | id, buyer_id, state, authorized_at, initial_deadline, expected_total_cents, currency, cancellation_requested_at?, completed_at?, version | Authorized checkout snapshot; positive total; initial deadline=authorized_at+900 elapsed seconds; state/timestamp checks; index buyer/date and due pending state |
| checkout_items | id, checkout_id, race_id, entitlement_id, listing_id, seller_id, availability_event_id, offer_version, price_cents, buyer_fee_cents, seller_margin_cents, buyer_total_cents, seller_net_cents, currency, fee_policy_version, registration_schema_version | Unique(checkout_id,race_id) and (checkout_id,entitlement_id); positive price/net; nonnegative fees; buyer_total=price+buyer_fee, seller_net=price-seller_margin; composite context FKs |
| checkout_registration_snapshots | checkout_item_id, buyer_name, buyer_email, registration_fields jsonb, schema_version, participation_snapshot | PK/FK item; immutable historical input with independent retention; no account-profile edits rewriting it; authorized retention redaction affects private fields only |
| checkout_acknowledgments | checkout_item_id, acknowledgment_kind, text_version, accepted_at | PK(item,kind); explicit transfer-procedure acknowledgment for each nonparticipating item; absence fails authorization; no default-true flag |
| checkout_item_locks | id, checkout_id, checkout_item_id, entitlement_id, listing_id, state, initial_expires_at, protected_until?, reconciliation_state, released_at? | Unique checkout_item_id; partial unique entitlement_id where state='protected'; consumed/released are terminal; index due initial_expires_at and reconciliation_state |
| buyer_race_guards | buyer_id, race_id, reserved_checkout_id?, version | PK(buyer_id,race_id); FK reservation to checkout with same buyer and corresponding race item; stable guard remains after release |
| purchases | id, checkout_id, buyer_id, payment_attempt_id, confirmed_at, total_cents, currency | Unique checkout_id and definitive completing payment_attempt_id; positive total; immutable full checkout completion |
| purchase_items | id, purchase_id, checkout_item_id, entitlement_id, listing_id, race_id, seller_id, buyer_id, ownership_sequence_no | Unique checkout_item_id and listing_id; immutable linkage to snapshots; no duplicate sale of one listing; subsequent relisting creates a different listing |
| official_registration_updates | id, purchase_item_id, state, request_version, requested_at, applied_at?, evidence_object_id?, failure_code? | Unique(item,request_version); applied requires evidenced result; scope and integration details remain a feature gate |

Purchase item amounts are obtained from their immutable checkout_item reference rather than copied into mutable live listing columns. Account contact changes cannot alter registration/acknowledgment snapshots. A separately authorized retention purge may redact personal fields while preserving structural IDs, financial amounts, acknowledgment versions/timestamps and audit evidence; its retention/legal-hold policy must be settled before enabling that operation. Totals across items and required acknowledgments are transaction validations; CHECK constraints cannot safely reference other rows/tables. A completed purchase must contain every authorized item; the application finalizer and audited integrity checks enforce this cardinality.

The protected-lock partial index depends on explicit persisted state, never `expires_at > now()`. An overdue processing/unknown lock stays protected until reconciliation proves safe release. Owner uniqueness plus durable buyer/race reservations prevents both same-bib double sale and two different same-race checkouts by one buyer. Clear reservations only after definitive cancellation or whole purchase completion.

## 6. Payment, seller obligations and recovery

| Table | Main columns | Keys, checks and indexes |
|---|---|---|
| seller_accounts | id, seller_id, provider, provider_scope, connected_account_ref, readiness_state, verified_at?, last_reconciled_at?, version | Unique(provider,scope,connected_account_ref), unique(seller_id,provider,scope); reuse account; backend-confirmed transfer readiness |
| seller_financial_guards | seller_id, version | PK/FK account; stable row shared by transfer preparation and hold/suspension changes |
| payment_attempts | id, checkout_id, attempt_no, expected_amount_cents, currency, state, validated_at?, validation_event_id?, last_reconciled_at?, operation_id | Unique(checkout_id,attempt_no); operation unique; definitive completing attempt recorded on purchase; preserve extra successful charges for technical correction |
| provider_object_references | id, provider, provider_scope, object_kind, object_ref, operation_id?, payment_attempt_id?, seller_account_id? | Unique(provider,scope,kind,ref); distinguish Session, PaymentIntent, charge, refund, transfer, connected account and bank payout; typed association rules |
| seller_payables | id, purchase_item_id, seller_id, seller_account_id, amount_cents, currency, validated_at, eligible_at, state, transferred_at?, consecutive_failures, next_attempt_on?, version | Unique purchase_item_id; amount>0; eligible_at is validated_at+259200 elapsed seconds; due index on eligible_at for unpaid; immutable original net |
| payable_holds | id, payable_id, investigation_id, placed_at, released_at?, released_by?, release_reason? | Unique active hold per payable/investigation; multiple investigations independently hold the same obligation |
| seller_transfer_suspensions | id, seller_id, investigation_id, placed_at, lifted_at?, lifted_by?, lift_reason? | One active suspension per seller/investigation; any active suspension blocks transfer; only super-admin lifts with reason/audit |
| financial_operations | id, semantic_key, kind, checkout_id?, payable_id?, payment_attempt_id?, recovery_receivable_id?, authorization_id?, expected_amount_cents, currency, beneficiary_ref?, parameters jsonb, parameters_hash, created_at, version | Unique semantic_key; typed target/checks by operation kind; immutable amount/beneficiary/parameters; no unknown operation silently replaced |
| financial_operation_attempts | id, operation_id, attempt_no, scheduled_run_id?, idempotency_key, state, prepared_at, submitted_at?, resolved_at?, provider_ref?, error_code? | Unique(operation_id,attempt_no), provider-scoped idempotency key; one unresolved prepared/submitted/unknown attempt per operation; append-only original intent and result history |
| financial_operation_observations | id, attempt_id, source, provider_event_id?, observed_at, outcome, sanitized_evidence jsonb | Append-only observations support reconciliation/out-of-order evidence; secrets omitted |
| bank_payout_observations | id, seller_account_id, provider_scope, bank_payout_ref, observed_state, amount_cents, currency, observed_at, provider_event_id? | Deduplicated observation identity; never creates another seller payable/transfer because bank delivery failed |
| financial_authorizations | id, kind, investigation_id, actor_id, amount_cents, currency, reason, approved_at, revoked_at? | Refund/recovery authorization; actor's super-admin authority snapshot/audit; positive scoped amount |
| refund_allocations | id, authorization_id?, operation_id, payment_attempt_id, purchase_item_id?, correction_kind, principal_cents, buyer_fee_cents, seller_margin_cents | Policy approval required for policy refund; automatic duplicate/technical correction has explicit service authority; cap confirmed refunds against collected funds |
| recovery_receivables | id, seller_id, investigation_id, original_transfer_operation_id, principal_cents, currency, created_at | Track previously transferred exposure; original principal immutable; no automatic bank debit or future-payable offset |
| recovery_allocations | id, receivable_id, recovery_operation_id, amount_cents, confirmed_at | Unique confirmed effect; only confirmed recovery reduces outstanding receivable; avoid an independently mutable balance |

provider_scope distinguishes platform/provider account and live/test environment. Provider uniqueness is never assumed global across those scopes. Connected accounts and buyer/customer identity are distinct.

A financial operation identifies one intended effect. Definitive temporary failure may permit a new attempt of that operation at the next daily run, using a new attempt identity/idempotency key. Network retransmission or unknown outcome retains the original attempt and key until reconciled. One unresolved attempt per operation is database-protected. Local semantic identity survives provider idempotency-key expiry.

For seller transfers, count an actual definitive provider failure, not a skipped held/unready run or an ambiguous network result. Confirmed success resets the streak. Three consecutive actual failures create one deduplicated alert for that streak; next_attempt_on follows the next Europe/Paris daily run. The failed obligation remains unpaid.

Freeze original fees/net and record confirmed refunds/recoveries separately. Finite database arithmetic checks protect individual records; cumulative refund/recovery caps require transaction locks and aggregate validation. Refund ownership restoration and entitlement resale after refund remain unresolved and must not be inferred from a refunded amount. An approved financial authorization does not grant ordinary admins provider execution rights.

## 7. Support, delivery and operational records

| Table | Main columns | Keys, checks and indexes |
|---|---|---|
| investigations | id, reporter_id?, purchase_item_id?, listing_id?, seller_id?, kind, state, discovered_at?, reported_at, acknowledged_at?, decided_by?, decision_reason? | Typed subject/context checks; protected evidence; acknowledgment business-day policy handled by application calendar |
| investigation_evidence | investigation_id, private_object_id, submitted_by?, submitted_at | Composite key; private proof/file access and retention hold |
| testimonies | id, author_id, content, moderation_state, moderated_by?, moderated_at?, published_at? | Published content has moderation evidence; exact submission/publication rules unresolved |
| recipient_preferences | account_id, optional_email_enabled, waitlist_defaults jsonb | PK account; essential account/payment notices cannot be suppressed by marketing preference |
| notifications | id, recipient_id, event_key, category, payload_version, private_payload jsonb, availability_event_id?, deadline?, created_at, read_at? | Unique(recipient,event_key); index recipient/created_at/id; recipient-only queries; waitlist payload preserves original deadline |
| notification_deliveries | id, notification_id, channel, state, attempt_count, next_attempt_at?, delivered_at? | Unique(notification,channel); due index; delivery never mutates eligibility |
| notification_delivery_attempts | id, delivery_id, attempt_no, attempted_at, outcome, error_code? | Unique(delivery,attempt_no); sanitized errors and explicit actual attempts |
| newsletter_subscriptions | id, email, email_normalized, subscribed, quit_reason?, join_at, quit_at? | Unique email_normalized; subscribed implies null quit fields; unsubscribe requires quit_at but optional reason; independent of accounts/waitlists |
| provider_event_inbox | id, provider, provider_scope, event_ref, received_at, authenticated_at, payload_version, protected_payload jsonb, processing_state, next_attempt_at?, processed_at? | Unique(provider,scope,event_ref); authenticated before acceptance; durable receipt before HTTP success; due index |
| outbox_messages | id, effect_key, aggregate_kind, aggregate_id, aggregate_version, event_type, payload_version, payload jsonb, created_at, dispatched_at?, next_attempt_at? | Unique effect_key; index pending next_attempt_at/created_at/id; transactionally inserted with business change |
| consumed_effects | consumer, effect_key, completed_at | Composite PK; deduplication committed in the same transaction as handler's database effect |
| scheduled_runs | id, job_name, local_run_date, timezone, scheduled_at, started_at?, completed_at?, state | Unique(job_name,local_run_date) for daily seller-release job; five-minute batching has its own explicit scheduled-instant identity |
| execution_claims | id, work_kind, work_id, run_id?, fencing_generation, claimed_by, claimed_at, lease_until, state | One active claim per work kind/id; durable fencing guards local writes; uncertain external outcome blocks mutation retry after lease expiry |
| administrative_audit | id, actor_account_id?, service_principal?, authority_snapshot, action, subject_kind, subject_id, reason?, occurred_at, outcome, operation_id? | Exactly one human/service actor identity; immutable audit; no raw proof, credentials or sensitive provider payload |
| retention_holds | id, category, subject_kind, subject_id, investigation_id, placed_at, released_at? | Active hold prevents affected purge; financial/evidence retention durations still open |

Polymorphic audit/outbox/retention subject references are intentional metadata, not substitutes for business foreign keys. Financial and ownership tables use typed FKs. Provider inbox payloads contain personal/payment information and require protected access and retention design; they are not application logs or public analytics.

Operational logs retain 30 days; aggregate analytics 90 days. Use logging/storage retention configuration for logs rather than a giant application log table. If aggregate analytics are persisted, use date + metric dimensions + nonnegative count with no emails, proof contents or provider identifiers. Newsletter retention/history remain open; this plan does not invent an event-history table for it.

## 8. Representative integrity SQL

Illustrative patterns only; they assume the preceding tables exist. Executable migrations must specify the entire column/nullability/FK contract and be verified on PostgreSQL.

```sql
CREATE UNIQUE INDEX uq_entitlement_current_owner_race
    ON bib_entitlements (current_owner_account_id, race_id)
    WHERE current_owner_account_id IS NOT NULL;

CREATE UNIQUE INDEX uq_listing_published_entitlement
    ON resale_listings (entitlement_id)
    WHERE state = 'published';

CREATE UNIQUE INDEX uq_checkout_protected_entitlement
    ON checkout_item_locks (entitlement_id)
    WHERE state = 'protected';

CREATE UNIQUE INDEX uq_active_waitlist_membership
    ON race_waitlist_memberships (race_id, account_id)
    WHERE ended_at IS NULL;

ALTER TABLE checkout_items
    ADD CONSTRAINT ck_checkout_item_amounts CHECK (
        price_cents > 0 AND buyer_fee_cents >= 0
        AND seller_margin_cents >= 0 AND seller_net_cents > 0
        AND buyer_total_cents = price_cents + buyer_fee_cents
        AND seller_net_cents = price_cents - seller_margin_cents
        AND currency = 'EUR'
    );
```

All arithmetic fields and currency in this example are NOT NULL.

Composite context FKs require corresponding non-partial UNIQUE keys, e.g. checkout_items(listing_id,entitlement_id,race_id) to a listing context key, and an acknowledgment or lock to the exact checkout item. Repeated context columns must match their source; simple independent FKs to existing but unrelated rows are insufficient. Do not use nullable composite keys where a required relationship could be skipped under MATCH SIMPLE.

PostgreSQL supports [partial unique indexes](https://www.postgresql.org/docs/17/indexes-partial.html). [CHECK and foreign-key constraints](https://www.postgresql.org/docs/17/ddl-constraints.html) have different responsibilities: a CHECK cannot replace cross-row business validation, and nullable composite FKs require care.

## 9. Transaction and concurrency protocol

Use short READ COMMITTED transactions with explicit row locking, revalidation and constraints. No provider request runs while database locks are held. A deadlock or serialization failure retries the entire safe database transaction with bounded attempts; unknown external operations are reconciled rather than repeated as part of that retry.

Proposed lock order across competing marketplace commands:

1. Required race snapshot guards, sorted by race ID.
2. Required buyer/race guards, sorted by buyer ID and race ID; insert absent guard with conflict handling before locking it.
3. Entitlements, sorted by entitlement ID.
4. Listings, sorted by listing ID.
5. Checkout root, then its item locks.
6. Relevant seller financial guard/account, payable rows and operation rows in stable ID order for financial workflows.

Discover candidate relationships first; acquire locks in the shared order, reread relationships/versions and retry if the affected set changed. Withdrawal discovers the active checkout and all of its items before using the same order. Completion and cancellation include guard rows for buyer and affected owners so ownership uniqueness is never raced. Do not lock a checkout first and then its entitlements in one handler while another reverses that order.

Publication, price change, join and leave share the race guard so the offer snapshot has a deterministic membership boundary. Checkout authorization commits all items, immutable snapshots, buyer/race reservations, protected locks and payment-operation intent together or none. Completion commits purchase/items, ownership transitions, listing sale states, waitlist removals, payable obligations and outbox in one transaction.

Seller transfer and suspension creation/lifting share a stable seller financial guard so a new hold cannot pass an unsubmitted transfer claim unnoticed. A hold received after provider submission triggers reconciliation; it cannot undo an already submitted movement through a local row lock. Each external mutation retains operation/attempt identity and updates only with the correct fencing generation.

Use FOR UPDATE SKIP LOCKED for queue-like due work/outbox claiming, not to silently skip an item in all-or-nothing checkout. PostgreSQL documents [locking](https://www.postgresql.org/docs/17/explicit-locking.html) and [SKIP LOCKED](https://www.postgresql.org/docs/17/sql-select.html); worker leasing is separate from payment safety. Recheck eligibility, holds and readiness after acquiring a claim and before submitting the operation.

## 10. Initial query indexes

Add targeted B-tree indexes alongside correctness constraints; unique/PK constraints already supply their own indexes. Index frequently joined FK columns and use EXPLAIN with realistic fixture data before adding broader indexes.

| Query | Proposed index |
|---|---|
| Race discovery / organization races | races(starts_at,id); races(organization_id,starts_at,id) |
| Published race offers | resale_listings(race_id,published_at,id) where state='published'; race_id is maintained through composite context FK |
| Seller listing history | resale_listings(seller_id,created_at,id) |
| Waitlist snapshot | race_waitlist_memberships(race_id,joined_at,account_id) where ended_at IS NULL |
| Active offer lookup | availability_events(listing_id,offer_version); lookup current version, reject invalidated event |
| Buyer checkout/purchase history | checkouts(buyer_id,authorized_at,id); purchases(buyer_id,confirmed_at,id) |
| Payment reconciliation | payment_attempts(state,last_reconciled_at,id); persist last_reconciled_at when introducing scanner |
| Due locks | checkout_item_locks(initial_expires_at,id) where state='protected' |
| Seller release | seller_payables(eligible_at,id) where state='unpaid'; seller_payables(seller_id,state) |
| Active holds/suspensions | payable_holds(payable_id) / seller_transfer_suspensions(seller_id) where corresponding release/lift time IS NULL |
| Outbox/provider receipt work | pending-state/undispatched partial indexes over next_attempt_at and id |
| Notification inbox | notifications(recipient_id,created_at DESC,id DESC); optional unread index if justified |
| Original eligibility lookup | original_registrations(race_id,bib_number), already unique |

For chronological keyset pagination use timestamp plus stable ID. No speculative JSONB GIN/trigram/search extension until accepted filters and measured queries justify them. Time-relative predicates such as now() are not partial-index membership criteria; index explicit persisted state and compare due timestamps in queries.

## 11. Migration sequence and validation

Select Doctrine migrations with DBAL at the first persistence slice; keep SQL migrations in Infrastructure/migrations. Do not generate every planned table before its first use case. Each stage adds the minimum complete schema needed by its TDD slice.

| Stage | Tables / constraints | Required evidence |
|---|---|---|
| 1 | Identity/organization/race foundation; private data separation | Empty database migration, upgrade replay, unique identity, owner/manager/cross-organization authorization |
| 2 | Original registrations, imports, entitlements, proof/listing and seller readiness | Leading-zero bibs, skipped duplicates, protected update fields, one owner/race, competing publication |
| 3 | Waitlist guards, membership and versioned availability snapshot | Concurrent join/publish boundary, late exclusion, empty snapshot, expiry and invalidation |
| 4 | Cart/checkout snapshots, reservations/locks; financial journal and inbox/outbox required for payment | Two connections competing, whole-cart rollback, same-buyer/race race, durable unknown Session creation |
| 5 | Purchase/ownership history, seller payables and completion effects | Atomic full completion, duplicated/out-of-order events, withdrawal racing success, restart/replay |
| 6 | Claims/schedules, holds/suspensions, refund/recovery and official-registration follow-up | 72 elapsed hours/DST, overlap, next-day retry, failed streak, unknown transfer, super-admin denial |
| 7 | Notifications, newsletter, support/testimony, retention/audit completion | Recipient isolation, independent rejoin, anonymization/history, reviewed retention gates |

Inbox/outbox/audit tables are introduced as soon as a stage produces their first real effect, not deferred merely because they appear later in the inventory. Add circular references (e.g. purchase items and ownership transitions) after both tables exist using an explicit migration order. Consider deferred FKs only for necessary atomic creation; retain non-deferrable referenced unique keys.

Before each persistence slice, agree its application/database test seam. Tests use an isolated PostgreSQL test database and independent connections for concurrency; never run writes against the development app database. Verify constraints, rollback, crash boundaries and migration compatibility as observable repository/use-case outcomes. Use the accepted coverage/quality gates; coverage cannot establish concurrency correctness.

For populated databases use expand/backfill/validate/contract changes, with deployment-compatible releases and operational backup/restore evidence. Plan concurrent index creation outside the ordinary migration transaction when needed. Destructive down migrations are not the production recovery strategy. Do not hard-delete financial references during schema rollback or anonymization.

## 12. Gates and implementation choices still open

- Canonical email/bib normalization, registration-field schemas, import update allowlist and limits.
- Behavior when a resale deadline is absent; no guessed global cutoff.
- Proof, payment/newsletter data retention, protected payload encryption/key management and private-file review policy.
- Refund-related ownership/restoration/resale decisions and concrete provider recovery capabilities.
- Receipt fields, official-registration integration evidence and testimony moderation rules.
- Exact claim lease/reconciliation cadence and escalation; no numeric SLA invented.
- Package selection for migrations, queue/storage adapters and Cloud runtime proof; Symfony 8.1 currently selected, earlier 8.2 target still recorded in workspace specs.

No database/schema change is applied by saving this plan. Next work is the fee-policy TDD slice followed by identity/race/entitlement persistence with agreed seams and isolated database tests.
