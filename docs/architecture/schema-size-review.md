# Schema size review against the existing Symfony models

Reviewed 2026-10-08. Recommendation for discussion; no models/migrations changed. The original schema inventory contains **64 proposed tables**. It is an exhaustive responsibility inventory and is too granular to treat as the starting migration set.

## Existing application comparison

`api-bib/src/Entity` has **17 Doctrine entities**, plus the `chat_room_user` join table visible in migrations. Address and ProviderProfile are embedded objects, not separate tables. These counts come from source/migrations, not an inspection of the live database. The new symfony-app contains health/bootstrap classes and no marketplace persistence models yet.

| Existing model | What to preserve / change |
|---|---|
| User + Profile + embedded ProviderProfile | Keep account/private profile; store current password/Google subject, roles and preferences without automatically introducing separate tables for each field group |
| Organization + Race | Keep; add explicit scoped membership/owner-manager roles. Lock existing race rows for availability snapshots rather than making a race guard table |
| BibSlot | Preserve imported original-runner eligibility. It can be original-holder fields on the stable entitlement instead of three registration/private-data/entitlement tables |
| RunnerBib | Currently stores price, owner, status and transaction reference. Separate stable entitlement from each sale listing so relisting preserves identity/history |
| Transaction | Preserve payment correlation; introduce immutable item/fee/registration snapshots, payment reconciliation and seller obligations rather than a single mutable status/float amount |
| Waitlist + WaitlistEntry | A membership can reference Race directly; a separate Waitlist container is unnecessary without its own independent rules |
| CartItem | Reference buyer and listing directly; no Cart root table is required for the current cart scope. Listing association must allow multiple buyers' non-reserving carts |
| Notification + Testimony | Keep simple records; do not create delivery/attempt tables before channel complexity or reporting needs justify them |
| Session, RefreshToken, ResetPasswordRequest | Authentication-storage choices; do not automatically copy JWT/session tables into the integrated web application |
| ChatRoom + ChatMessage + participant join | Deferred from launch |

The smaller legacy model is useful, but it does not supply the accepted immutable checkout snapshots, shared-offer recipient snapshots, relisting ownership history, durable financial intent or inbox/outbox guarantees. Retain those responsibilities in the rebuild.

## First simplification pass: 64 → 40

This is a conservative **40-table full-scope candidate**, not a minimum requirement or approval to create all 40. It removes 24 separately proposed tables while preserving the current model boundaries. Further simplification should follow implemented use cases rather than a fixed table-count budget.

| Combine / remove separate table | Destination and condition |
|---|---|
| authentication_identities, account_roles, recipient_preferences | Account columns/roles/preferences; accepted Google plus password scope. Add identity table later if multiple provider identities become a real requirement |
| race_registration_fields | Versioned field-definition JSON on Race; immutable checkout schema snapshot remains |
| eligibility_import_rows | Immutable versioned row-result report on EligibilityImport, or a private report object; only split if volume/query needs justify it |
| original_registrations, original_registration_private_data | BibEntitlement original-holder/source fields; private columns accessed only by explicit private adapters, excluded from public read models and redacted separately when authorized |
| proof_reviews | Exact proof-version decision columns plus append-only AdministrativeAudit events for prior reviews; do not overwrite evidence without history |
| race_availability_guards | Lock Race row for join/leave/publication snapshot boundary |
| carts | CartItem keyed by buyer/listing |
| checkout_registration_snapshots, checkout_acknowledgments, checkout_item_locks | CheckoutItem has immutable amounts/registration/explicit acknowledgment and separately mutable durable protection columns; partial unique protected entitlement still required; freeze only snapshot columns |
| seller_financial_guards | Lock existing Account row for seller transfer/suspension coordination |
| provider_object_references | Typed provider-scoped reference columns on their actual owner: PaymentAttempt, SellerAccount, FinancialOperation/Attempt; preserve kind-specific uniqueness |
| financial_operation_observations | Append protected observations through journal/audit/inbox while keeping attempt intent immutable; current attempt result is a projection of evidence |
| bank_payout_observations | SellerAccount bank-settlement projection with deduplicated inbox/audit history; never infer transfer retry from bank failure |
| financial_authorizations | FinancialOperation stores actor/authority snapshot, approved amount/reason/time; append-only audit preserves decisions; retain super-admin and technical-correction distinction |
| recovery_allocations | Confirmed recovery FinancialOperation references one receivable; total confirmed operations computes recovered amount |
| official_registration_updates | PurchaseItem follow-up columns plus immutable audit/outbox evidence and attempt/version identity; applied status still requires evidence |
| notification_deliveries, notification_delivery_attempts | Notification per-channel delivery state/retry counters, with durable deduplicated delivery intent in outbox; detailed attempts use existing operational/audit records |
| execution_claims | Claim/lease/fencing fields on the claimed checkout, operation attempt, inbox/outbox or scheduled work row; no generic extra table needed |
| retention_holds | Investigation subject references/hold metadata plus existing payable/seller holds; purge queries must check unresolved cases |

Combining physical records does not combine domain lifecycles. Domain models/value objects can map to columns in one table. Preserve private-data access, immutable financial snapshots, typed references and concurrency constraints. JSON is suitable for bounded versioned snapshots/reports; keep indexed financial identities, cents, lock state and foreign keys in typed columns.

## Candidate inventory: 40 tables

| Area | Tables | Count |
|---|---|---|
| Accounts/organizations | accounts, account_private_profiles, account_credentials, organizations, organization_memberships | 5 |
| Race/inventory/listings | races, eligibility_imports, bib_entitlements, ownership_transitions, private_objects, ownership_proofs, resale_listings | 7 |
| Availability | race_waitlist_memberships, availability_events, availability_recipients | 3 |
| Cart/checkout/purchase | cart_items, checkouts, checkout_items, buyer_race_guards, purchases, purchase_items | 6 |
| Financial follow-up | seller_accounts, payment_attempts, seller_payables, payable_holds, seller_transfer_suspensions, financial_operations, financial_operation_attempts, refund_allocations, recovery_receivables | 9 |
| Support/communication | investigations, investigation_evidence, testimonies, notifications, newsletter_subscriptions | 5 |
| Durable operations | provider_event_inbox, outbox_messages, consumed_effects, scheduled_runs, administrative_audit | 5 |

Migration bookkeeping or framework-selected session/transport tables are not included. Original detailed-schema SQL/columns are reference material and cannot be applied unchanged to this candidate: every combined table needs its updated column/constraint contract when its use case is implemented.

## Delivery recommendation

Start with approximately 8–10 foundation tables as the first identity/race/inventory slices require them; fee-policy TDD needs no table. Add availability, checkout and financial durability before those corresponding capabilities go live. Post-transfer investigation/refund/recovery support is a launch gate for real-money operations, not optional removal merely to meet a lower count. Chat remains deferred.

The important separations to retain are entitlement/listing, live offer/member snapshot, checkout/item snapshots, ownership history, seller payable and financial operation identity. These prevent lost history, repricing of purchases, double sales and duplicate financial effects. The table count itself is not a Clean Architecture requirement.
