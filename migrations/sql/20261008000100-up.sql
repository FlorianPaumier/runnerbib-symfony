CREATE EXTENSION IF NOT EXISTS pg_trgm;
-- statement

CREATE TABLE accounts (
 id uuid PRIMARY KEY, lifecycle_state text NOT NULL DEFAULT 'active' CHECK (lifecycle_state IN ('active','suspended','anonymized')),
 password_hash text, google_subject text UNIQUE, roles jsonb NOT NULL DEFAULT '["runner"]' CHECK (jsonb_typeof(roles)='array'),
 optional_email_enabled boolean NOT NULL DEFAULT false, waitlist_defaults jsonb NOT NULL DEFAULT '{}',
 verified_at timestamptz, terms_version text NOT NULL, terms_accepted_at timestamptz NOT NULL, anonymized_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP, version bigint NOT NULL DEFAULT 1 CHECK(version>0),
 CHECK ((lifecycle_state='anonymized') = (anonymized_at IS NOT NULL)), CHECK(jsonb_typeof(waitlist_defaults)='object')
);
-- statement
CREATE TABLE account_private_profiles (
 account_id uuid PRIMARY KEY REFERENCES accounts ON DELETE RESTRICT, email text NOT NULL, email_normalized text NOT NULL UNIQUE,
 display_name text, registration_profile jsonb NOT NULL DEFAULT '{}' CHECK(jsonb_typeof(registration_profile)='object'),
 CHECK(btrim(email_normalized)<>'')
);
-- statement
CREATE TABLE account_credentials (
 id uuid PRIMARY KEY, account_id uuid NOT NULL REFERENCES accounts ON DELETE RESTRICT,
 purpose text NOT NULL CHECK(purpose IN ('verification','password_reset')), token_hash text NOT NULL UNIQUE,
 created_at timestamptz NOT NULL, expires_at timestamptz NOT NULL, consumed_at timestamptz, CHECK(expires_at>created_at)
);
-- statement
CREATE TABLE organizations (
 id uuid PRIMARY KEY, name text NOT NULL CHECK(btrim(name)<>''), approval_state text NOT NULL DEFAULT 'pending' CHECK(approval_state IN ('pending','approved','rejected','suspended')),
 approved_by uuid REFERENCES accounts ON DELETE RESTRICT, approved_at timestamptz, version bigint NOT NULL DEFAULT 1 CHECK(version>0),
 CHECK(approval_state<>'approved' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL))
);
-- statement
CREATE TABLE organization_memberships (
 id uuid PRIMARY KEY, organization_id uuid NOT NULL REFERENCES organizations ON DELETE RESTRICT, account_id uuid NOT NULL REFERENCES accounts ON DELETE RESTRICT,
 role text NOT NULL CHECK(role IN ('owner','manager')), joined_at timestamptz NOT NULL, ended_at timestamptz, CHECK(ended_at IS NULL OR ended_at>=joined_at)
);
-- statement
CREATE UNIQUE INDEX uq_membership_active_account ON organization_memberships(account_id) WHERE ended_at IS NULL;
-- statement
CREATE UNIQUE INDEX uq_membership_active_owner ON organization_memberships(organization_id) WHERE ended_at IS NULL AND role='owner';
-- statement
CREATE INDEX ix_membership_organization ON organization_memberships(organization_id,joined_at);
-- statement

CREATE TABLE races (
 id uuid PRIMARY KEY, organization_id uuid REFERENCES organizations ON DELETE RESTRICT,
 name varchar(255) NOT NULL CHECK(btrim(name)<>''), starts_at timestamptz NOT NULL,
 timezone varchar(64) NOT NULL CHECK(btrim(timezone)<>''), sport_code varchar(64) NOT NULL CHECK(btrim(sport_code)<>''), distance_metres integer NOT NULL CHECK(distance_metres>0),
 street varchar(255), city varchar(255) NOT NULL CHECK(btrim(city)<>''), region varchar(255), postal_code varchar(16), country_code varchar(2) NOT NULL CHECK(country_code ~ '^[A-Z]{2}$'),
 latitude numeric(9,6) CHECK(latitude BETWEEN -90 AND 90), longitude numeric(9,6) CHECK(longitude BETWEEN -180 AND 180),
 description text, image_url text, official_url text,
 publication_state varchar(16) NOT NULL DEFAULT 'draft' CHECK(publication_state IN ('draft','published','archived')),
 participation varchar(24) NOT NULL DEFAULT 'nonparticipating' CHECK(participation IN ('participating','nonparticipating')),
 resale_deadline timestamptz, min_price_cents bigint CHECK(min_price_cents>0), max_price_cents bigint CHECK(max_price_cents>0),
 registration_schema_version integer CHECK(registration_schema_version>0), registration_schema jsonb CHECK(jsonb_typeof(registration_schema)='object'),
 created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL, version bigint NOT NULL DEFAULT 1 CHECK(version>0),
 CHECK((latitude IS NULL)=(longitude IS NULL)), CHECK(updated_at>=created_at),
 CHECK(participation<>'participating' OR organization_id IS NOT NULL),
 CHECK(participation<>'nonparticipating' OR (min_price_cents IS NULL AND max_price_cents IS NULL)),
 CHECK(min_price_cents IS NULL OR max_price_cents IS NULL OR min_price_cents<=max_price_cents),
 CHECK((registration_schema_version IS NULL)=(registration_schema IS NULL))
);
-- statement
CREATE INDEX ix_races_public_start ON races(starts_at,id) WHERE publication_state='published';
-- statement
CREATE INDEX ix_races_public_sport_start ON races(sport_code,starts_at,id) WHERE publication_state='published';
-- statement
CREATE INDEX ix_races_organization_start ON races(organization_id,starts_at,id) WHERE organization_id IS NOT NULL;
-- statement
CREATE INDEX ix_races_public_name ON races USING gin(name gin_trgm_ops) WHERE publication_state='published';
-- statement

CREATE TABLE private_objects (
 id uuid PRIMARY KEY, object_key text NOT NULL UNIQUE, category text NOT NULL, mime_type text NOT NULL, size_bytes bigint NOT NULL CHECK(size_bytes>=0), checksum text NOT NULL,
 uploaded_by uuid REFERENCES accounts ON DELETE RESTRICT, created_at timestamptz NOT NULL, delete_after timestamptz, purged_at timestamptz
);
-- statement
CREATE TABLE eligibility_imports (
 id uuid PRIMARY KEY, race_id uuid NOT NULL REFERENCES races ON DELETE RESTRICT, actor_id uuid NOT NULL REFERENCES accounts ON DELETE RESTRICT,
 mode text NOT NULL CHECK(mode IN ('skip_invalid','update')), private_object_id uuid REFERENCES private_objects ON DELETE RESTRICT,
 state text NOT NULL CHECK(state IN ('pending','processing','completed','failed')), started_at timestamptz NOT NULL, completed_at timestamptz,
 summary jsonb NOT NULL DEFAULT '{}' CHECK(jsonb_typeof(summary)='object'), row_results jsonb CHECK(jsonb_typeof(row_results)='array'),
 UNIQUE(id,race_id)
);
-- statement
CREATE TABLE bib_entitlements (
 id uuid PRIMARY KEY, race_id uuid NOT NULL REFERENCES races ON DELETE RESTRICT, bib_number text NOT NULL CHECK(btrim(bib_number)<>''),
 source_kind text NOT NULL CHECK(source_kind IN ('import','document')), import_id uuid, original_holder_name text, original_runner_email text,
 original_fields jsonb NOT NULL DEFAULT '{}' CHECK(jsonb_typeof(original_fields)='object'), current_owner_account_id uuid REFERENCES accounts ON DELETE RESTRICT,
 version bigint NOT NULL DEFAULT 1 CHECK(version>0), UNIQUE(race_id,bib_number), UNIQUE(id,race_id),
 FOREIGN KEY(import_id,race_id) REFERENCES eligibility_imports(id,race_id) ON DELETE RESTRICT,
 CHECK((source_kind='import' AND import_id IS NOT NULL) OR (source_kind='document' AND import_id IS NULL))
);
-- statement
CREATE UNIQUE INDEX uq_entitlement_owner_race ON bib_entitlements(current_owner_account_id,race_id) WHERE current_owner_account_id IS NOT NULL;
-- statement
CREATE TABLE ownership_proofs (
 id uuid PRIMARY KEY, submitted_by uuid NOT NULL REFERENCES accounts ON DELETE RESTRICT, race_id uuid NOT NULL REFERENCES races ON DELETE RESTRICT,
 proposed_bib_number text NOT NULL, entitlement_id uuid, source_kind text NOT NULL CHECK(source_kind IN ('import','document')),
 private_object_id uuid REFERENCES private_objects ON DELETE RESTRICT, submitted_at timestamptz NOT NULL,
 proof_version bigint NOT NULL DEFAULT 1 CHECK(proof_version>0), decision text CHECK(decision IN ('approved','rejected')), reviewed_version bigint,
 reviewer_id uuid REFERENCES accounts ON DELETE RESTRICT, reviewed_at timestamptz, review_reason text,
 UNIQUE(id,race_id), UNIQUE(id,race_id,submitted_by), FOREIGN KEY(entitlement_id,race_id) REFERENCES bib_entitlements(id,race_id) ON DELETE RESTRICT,
 CHECK(source_kind<>'document' OR private_object_id IS NOT NULL),
 CHECK((decision IS NULL AND reviewed_version IS NULL AND reviewer_id IS NULL AND reviewed_at IS NULL) OR (decision IS NOT NULL AND reviewed_version IS NOT NULL AND reviewed_version=proof_version AND reviewer_id IS NOT NULL AND reviewed_at IS NOT NULL))
);
-- statement
CREATE TABLE resale_listings (
 id uuid PRIMARY KEY, race_id uuid NOT NULL REFERENCES races ON DELETE RESTRICT, entitlement_id uuid, seller_id uuid NOT NULL REFERENCES accounts ON DELETE RESTRICT,
 proof_id uuid, approved_proof_version bigint, price_cents bigint NOT NULL CHECK(price_cents>0), currency text NOT NULL DEFAULT 'EUR' CHECK(currency='EUR'),
 state text NOT NULL DEFAULT 'draft' CHECK(state IN ('draft','published','withdrawn','sold')), offer_version bigint NOT NULL DEFAULT 1 CHECK(offer_version>0),
 published_at timestamptz, withdrawn_at timestamptz, sold_at timestamptz, version bigint NOT NULL DEFAULT 1 CHECK(version>0),
 UNIQUE(id,entitlement_id,race_id,seller_id), UNIQUE(id,race_id),
 FOREIGN KEY(entitlement_id,race_id) REFERENCES bib_entitlements(id,race_id) ON DELETE RESTRICT,
 FOREIGN KEY(proof_id,race_id,seller_id) REFERENCES ownership_proofs(id,race_id,submitted_by) ON DELETE RESTRICT,
 CHECK(state='draft' OR (entitlement_id IS NOT NULL AND proof_id IS NOT NULL AND approved_proof_version IS NOT NULL AND published_at IS NOT NULL)),
 CHECK(state<>'withdrawn' OR withdrawn_at IS NOT NULL), CHECK(state<>'sold' OR sold_at IS NOT NULL)
);
-- statement
CREATE UNIQUE INDEX uq_listing_live_entitlement ON resale_listings(entitlement_id) WHERE state='published';
-- statement
CREATE INDEX ix_listing_race_time ON resale_listings(race_id,published_at,id) WHERE state='published';
-- statement
CREATE INDEX ix_listing_race_price ON resale_listings(race_id,price_cents,id) WHERE state='published';
-- statement
CREATE TABLE race_waitlist_memberships (
 id uuid PRIMARY KEY, race_id uuid NOT NULL REFERENCES races ON DELETE RESTRICT, account_id uuid NOT NULL REFERENCES accounts ON DELETE RESTRICT,
 joined_at timestamptz NOT NULL, ended_at timestamptz, email_enabled boolean NOT NULL DEFAULT true, in_app_enabled boolean NOT NULL DEFAULT true,
 UNIQUE(id,race_id,account_id), CHECK(ended_at IS NULL OR ended_at>=joined_at)
);
-- statement
CREATE UNIQUE INDEX uq_waitlist_active ON race_waitlist_memberships(race_id,account_id) WHERE ended_at IS NULL;
-- statement
CREATE INDEX ix_waitlist_snapshot ON race_waitlist_memberships(race_id,joined_at,account_id) WHERE ended_at IS NULL;
-- statement
CREATE TABLE availability_events (
 id uuid PRIMARY KEY, listing_id uuid NOT NULL, race_id uuid NOT NULL, offer_version bigint NOT NULL CHECK(offer_version>0),
 price_cents bigint NOT NULL CHECK(price_cents>0), currency text NOT NULL CHECK(currency='EUR'), available_at timestamptz NOT NULL,
 priority_ends_at timestamptz NOT NULL, recipient_count integer NOT NULL CHECK(recipient_count>=0), invalidated_at timestamptz, invalidation_reason text,
 UNIQUE(listing_id,offer_version), UNIQUE(id,listing_id,race_id,offer_version), UNIQUE(id,race_id),
 FOREIGN KEY(listing_id,race_id) REFERENCES resale_listings(id,race_id) ON DELETE RESTRICT,
 CHECK(priority_ends_at=available_at+INTERVAL '3600 seconds'), CHECK((invalidated_at IS NULL)=(invalidation_reason IS NULL))
);
-- statement
CREATE TABLE availability_recipients (
 event_id uuid NOT NULL, race_id uuid NOT NULL, account_id uuid NOT NULL, membership_id uuid NOT NULL, snapshotted_at timestamptz NOT NULL,
 PRIMARY KEY(event_id,account_id), FOREIGN KEY(event_id,race_id) REFERENCES availability_events(id,race_id) ON DELETE RESTRICT,
 FOREIGN KEY(membership_id,race_id,account_id) REFERENCES race_waitlist_memberships(id,race_id,account_id) ON DELETE RESTRICT
);
-- statement
CREATE TABLE cart_items (
 buyer_id uuid NOT NULL REFERENCES accounts ON DELETE RESTRICT, listing_id uuid NOT NULL REFERENCES resale_listings ON DELETE RESTRICT, added_at timestamptz NOT NULL,
 PRIMARY KEY(buyer_id,listing_id)
);
-- statement
CREATE TABLE checkouts (
 id uuid PRIMARY KEY, buyer_id uuid NOT NULL REFERENCES accounts ON DELETE RESTRICT,
 state text NOT NULL CHECK(state IN ('pending','processing','unknown','completed','cancelled','failed')), authorized_at timestamptz NOT NULL,
 initial_deadline timestamptz NOT NULL, expected_total_cents bigint NOT NULL CHECK(expected_total_cents>0), currency text NOT NULL CHECK(currency='EUR'),
 cancellation_requested_at timestamptz, completed_at timestamptz, version bigint NOT NULL DEFAULT 1 CHECK(version>0),
 claimed_by text, lease_until timestamptz, fencing_generation bigint NOT NULL DEFAULT 0 CHECK(fencing_generation>=0),
 UNIQUE(id,buyer_id), CHECK(initial_deadline=authorized_at+INTERVAL '900 seconds'), CHECK(state<>'completed' OR completed_at IS NOT NULL)
);
-- statement
CREATE INDEX ix_checkout_buyer ON checkouts(buyer_id,authorized_at,id);
-- statement
CREATE INDEX ix_checkout_reconcile ON checkouts(initial_deadline) WHERE state IN ('pending','processing','unknown');
-- statement
CREATE TABLE checkout_items (
 id uuid PRIMARY KEY, checkout_id uuid NOT NULL REFERENCES checkouts ON DELETE RESTRICT, race_id uuid NOT NULL, entitlement_id uuid NOT NULL, listing_id uuid NOT NULL, seller_id uuid NOT NULL,
 availability_event_id uuid NOT NULL, offer_version bigint NOT NULL, price_cents bigint NOT NULL CHECK(price_cents>0), buyer_fee_cents bigint NOT NULL CHECK(buyer_fee_cents>=0),
 seller_margin_cents bigint NOT NULL CHECK(seller_margin_cents>=0), buyer_total_cents bigint NOT NULL CHECK(buyer_total_cents>0), seller_net_cents bigint NOT NULL CHECK(seller_net_cents>0),
 currency text NOT NULL CHECK(currency='EUR'), fee_policy_version text NOT NULL, registration_schema_version integer CHECK(registration_schema_version>0), registration_schema jsonb,
 buyer_name text, buyer_email text, registration_fields jsonb CHECK(jsonb_typeof(registration_fields)='object'), private_redacted_at timestamptz,
 participation_snapshot text NOT NULL CHECK(participation_snapshot IN ('participating','nonparticipating')),
 acknowledgment_text_version text, acknowledgment_accepted_at timestamptz,
 protection_state text NOT NULL DEFAULT 'protected' CHECK(protection_state IN ('protected','consumed','released')),
 initial_expires_at timestamptz NOT NULL, protected_until timestamptz, reconciliation_state text NOT NULL DEFAULT 'pending' CHECK(reconciliation_state IN ('pending','processing','unknown','resolved')),
 released_at timestamptz, UNIQUE(checkout_id,race_id), UNIQUE(checkout_id,entitlement_id), UNIQUE(id,entitlement_id,listing_id,race_id,seller_id),
 FOREIGN KEY(listing_id,entitlement_id,race_id,seller_id) REFERENCES resale_listings(id,entitlement_id,race_id,seller_id) ON DELETE RESTRICT,
 FOREIGN KEY(availability_event_id,listing_id,race_id,offer_version) REFERENCES availability_events(id,listing_id,race_id,offer_version) ON DELETE RESTRICT,
 CHECK(buyer_total_cents::numeric=price_cents::numeric+buyer_fee_cents::numeric), CHECK(seller_net_cents::numeric=price_cents::numeric-seller_margin_cents::numeric),
 CHECK(participation_snapshot<>'nonparticipating' OR (acknowledgment_text_version IS NOT NULL AND acknowledgment_accepted_at IS NOT NULL)),
 CHECK((private_redacted_at IS NULL AND buyer_name IS NOT NULL AND buyer_email IS NOT NULL AND registration_fields IS NOT NULL) OR (private_redacted_at IS NOT NULL AND buyer_name IS NULL AND buyer_email IS NULL AND registration_fields IS NULL)),
 CHECK(protection_state<>'released' OR released_at IS NOT NULL), CHECK((registration_schema_version IS NULL)=(registration_schema IS NULL)),
 CHECK(registration_schema IS NULL OR jsonb_typeof(registration_schema)='object')
);
-- statement
CREATE UNIQUE INDEX uq_checkout_protected_entitlement ON checkout_items(entitlement_id) WHERE protection_state='protected';
-- statement
CREATE INDEX ix_checkout_item_due ON checkout_items(initial_expires_at,reconciliation_state) WHERE protection_state='protected';
-- statement
CREATE TABLE buyer_race_guards (
 buyer_id uuid NOT NULL REFERENCES accounts ON DELETE RESTRICT, race_id uuid NOT NULL REFERENCES races ON DELETE RESTRICT,
 reserved_checkout_id uuid, version bigint NOT NULL DEFAULT 1 CHECK(version>0), PRIMARY KEY(buyer_id,race_id),
 FOREIGN KEY(reserved_checkout_id,buyer_id) REFERENCES checkouts(id,buyer_id) ON DELETE RESTRICT,
 FOREIGN KEY(reserved_checkout_id,race_id) REFERENCES checkout_items(checkout_id,race_id) ON DELETE RESTRICT
);
-- statement
CREATE TABLE seller_accounts (
 id uuid PRIMARY KEY, seller_id uuid NOT NULL REFERENCES accounts ON DELETE RESTRICT, provider text NOT NULL, provider_scope text NOT NULL, connected_account_ref text NOT NULL,
 readiness_state text NOT NULL CHECK(readiness_state IN ('pending','ready','restricted','disabled')), verified_at timestamptz, last_reconciled_at timestamptz,
 bank_payout_ref text, bank_payout_state text, bank_payout_observed_at timestamptz, version bigint NOT NULL DEFAULT 1 CHECK(version>0),
 UNIQUE(provider,provider_scope,connected_account_ref), UNIQUE(seller_id,provider,provider_scope), UNIQUE(id,seller_id)
);
-- statement
CREATE TABLE payment_attempts (
 id uuid PRIMARY KEY, checkout_id uuid NOT NULL REFERENCES checkouts ON DELETE RESTRICT, attempt_no integer NOT NULL CHECK(attempt_no>0),
 expected_amount_cents bigint NOT NULL CHECK(expected_amount_cents>0), currency text NOT NULL CHECK(currency='EUR'),
 state text NOT NULL CHECK(state IN ('prepared','submitted','unknown','succeeded','failed','cancelled')), validated_at timestamptz, validation_event_id uuid,
 last_reconciled_at timestamptz, operation_id uuid NOT NULL UNIQUE, provider text NOT NULL, provider_scope text NOT NULL,
 session_ref text, payment_intent_ref text, charge_ref text,
 UNIQUE(checkout_id,attempt_no), UNIQUE(id,checkout_id), UNIQUE(provider,provider_scope,session_ref), UNIQUE(provider,provider_scope,payment_intent_ref), UNIQUE(provider,provider_scope,charge_ref),
 CHECK(state<>'succeeded' OR validated_at IS NOT NULL)
);
-- statement
CREATE TABLE purchases (
 id uuid PRIMARY KEY, checkout_id uuid NOT NULL UNIQUE, buyer_id uuid NOT NULL, payment_attempt_id uuid NOT NULL UNIQUE,
 confirmed_at timestamptz NOT NULL, total_cents bigint NOT NULL CHECK(total_cents>0), currency text NOT NULL CHECK(currency='EUR'), UNIQUE(id,buyer_id),
 FOREIGN KEY(checkout_id,buyer_id) REFERENCES checkouts(id,buyer_id) ON DELETE RESTRICT,
 FOREIGN KEY(payment_attempt_id,checkout_id) REFERENCES payment_attempts(id,checkout_id) ON DELETE RESTRICT
);
-- statement
CREATE TABLE purchase_items (
 id uuid PRIMARY KEY, purchase_id uuid NOT NULL, checkout_item_id uuid NOT NULL UNIQUE, entitlement_id uuid NOT NULL, listing_id uuid NOT NULL UNIQUE,
 race_id uuid NOT NULL, seller_id uuid NOT NULL, buyer_id uuid NOT NULL, ownership_sequence_no bigint NOT NULL CHECK(ownership_sequence_no>0),
 registration_update_state text NOT NULL DEFAULT 'not_requested' CHECK(registration_update_state IN ('not_requested','pending','applied','failed')),
 registration_request_version bigint NOT NULL DEFAULT 0 CHECK(registration_request_version>=0), registration_requested_at timestamptz, registration_applied_at timestamptz,
 registration_evidence_object_id uuid REFERENCES private_objects ON DELETE RESTRICT, registration_failure_code text,
 UNIQUE(id,entitlement_id), UNIQUE(id,seller_id),
 FOREIGN KEY(purchase_id,buyer_id) REFERENCES purchases(id,buyer_id) ON DELETE RESTRICT,
 FOREIGN KEY(checkout_item_id,entitlement_id,listing_id,race_id,seller_id) REFERENCES checkout_items(id,entitlement_id,listing_id,race_id,seller_id) ON DELETE RESTRICT,
 CHECK(buyer_id<>seller_id), CHECK(registration_update_state<>'applied' OR (registration_applied_at IS NOT NULL AND registration_evidence_object_id IS NOT NULL))
);
-- statement
CREATE TABLE ownership_transitions (
 id uuid PRIMARY KEY, entitlement_id uuid NOT NULL REFERENCES bib_entitlements ON DELETE RESTRICT, sequence_no bigint NOT NULL CHECK(sequence_no>0),
 from_account_id uuid REFERENCES accounts ON DELETE RESTRICT, to_account_id uuid NOT NULL REFERENCES accounts ON DELETE RESTRICT,
 kind text NOT NULL CHECK(kind IN ('verified_claim','purchase')), purchase_item_id uuid UNIQUE, claim_proof_id uuid REFERENCES ownership_proofs ON DELETE RESTRICT,
 occurred_at timestamptz NOT NULL, effect_key text NOT NULL UNIQUE, UNIQUE(entitlement_id,sequence_no),
 FOREIGN KEY(purchase_item_id,entitlement_id) REFERENCES purchase_items(id,entitlement_id) ON DELETE RESTRICT,
 CHECK((kind='purchase' AND purchase_item_id IS NOT NULL AND claim_proof_id IS NULL) OR (kind='verified_claim' AND claim_proof_id IS NOT NULL AND purchase_item_id IS NULL)),
 CHECK(from_account_id IS NULL OR from_account_id<>to_account_id)
);
-- statement
CREATE TABLE seller_payables (
 id uuid PRIMARY KEY, purchase_item_id uuid NOT NULL UNIQUE, seller_id uuid NOT NULL, seller_account_id uuid NOT NULL,
 amount_cents bigint NOT NULL CHECK(amount_cents>0), currency text NOT NULL CHECK(currency='EUR'), validated_at timestamptz NOT NULL, eligible_at timestamptz NOT NULL,
 state text NOT NULL CHECK(state IN ('unpaid','transferring','transferred')), transferred_at timestamptz,
 consecutive_failures integer NOT NULL DEFAULT 0 CHECK(consecutive_failures>=0), next_attempt_on date, version bigint NOT NULL DEFAULT 1 CHECK(version>0),
 FOREIGN KEY(purchase_item_id,seller_id) REFERENCES purchase_items(id,seller_id) ON DELETE RESTRICT,
 FOREIGN KEY(seller_account_id,seller_id) REFERENCES seller_accounts(id,seller_id) ON DELETE RESTRICT,
 CHECK(eligible_at=validated_at+INTERVAL '259200 seconds'), CHECK(state<>'transferred' OR transferred_at IS NOT NULL)
);
-- statement
CREATE INDEX ix_payable_due ON seller_payables(eligible_at,next_attempt_on,id) WHERE state='unpaid';
-- statement
CREATE TABLE investigations (
 id uuid PRIMARY KEY, reporter_id uuid REFERENCES accounts ON DELETE RESTRICT, purchase_item_id uuid REFERENCES purchase_items ON DELETE RESTRICT,
 listing_id uuid REFERENCES resale_listings ON DELETE RESTRICT, seller_id uuid REFERENCES accounts ON DELETE RESTRICT,
 kind text NOT NULL, state text NOT NULL CHECK(state IN ('open','acknowledged','investigating','resolved','rejected')),
 discovered_at timestamptz, reported_at timestamptz NOT NULL, acknowledged_at timestamptz, decided_by uuid REFERENCES accounts ON DELETE RESTRICT, decision_reason text,
 retention_subjects jsonb NOT NULL DEFAULT '[]' CHECK(jsonb_typeof(retention_subjects)='array'), retention_review_at timestamptz,
 CHECK(num_nonnulls(purchase_item_id,listing_id,seller_id)>=1)
);
-- statement
CREATE TABLE payable_holds (
 id uuid PRIMARY KEY, payable_id uuid NOT NULL REFERENCES seller_payables ON DELETE RESTRICT, investigation_id uuid NOT NULL REFERENCES investigations ON DELETE RESTRICT,
 placed_at timestamptz NOT NULL, released_at timestamptz, released_by uuid REFERENCES accounts ON DELETE RESTRICT, release_reason text,
 CHECK(released_at IS NULL OR (released_at>=placed_at AND released_by IS NOT NULL AND release_reason IS NOT NULL AND btrim(release_reason)<>''))
);
-- statement
CREATE UNIQUE INDEX uq_payable_active_hold ON payable_holds(payable_id,investigation_id) WHERE released_at IS NULL;
-- statement
CREATE TABLE seller_transfer_suspensions (
 id uuid PRIMARY KEY, seller_id uuid NOT NULL REFERENCES accounts ON DELETE RESTRICT, investigation_id uuid NOT NULL REFERENCES investigations ON DELETE RESTRICT,
 placed_at timestamptz NOT NULL, lifted_at timestamptz, lifted_by uuid REFERENCES accounts ON DELETE RESTRICT, lift_reason text,
 CHECK(lifted_at IS NULL OR (lifted_at>=placed_at AND lifted_by IS NOT NULL AND lift_reason IS NOT NULL AND btrim(lift_reason)<>''))
);
-- statement
CREATE UNIQUE INDEX uq_seller_active_suspension ON seller_transfer_suspensions(seller_id,investigation_id) WHERE lifted_at IS NULL;
-- statement
CREATE TABLE scheduled_runs (
 id uuid PRIMARY KEY, job_name text NOT NULL, local_run_date date NOT NULL, timezone text NOT NULL, scheduled_at timestamptz NOT NULL,
 started_at timestamptz, completed_at timestamptz, state text NOT NULL CHECK(state IN ('pending','running','completed','failed')),
 claimed_by text, lease_until timestamptz, fencing_generation bigint NOT NULL DEFAULT 0 CHECK(fencing_generation>=0), UNIQUE(job_name,scheduled_at)
);
-- statement
CREATE UNIQUE INDEX uq_daily_seller_run ON scheduled_runs(job_name,local_run_date) WHERE job_name='seller_release';
-- statement
CREATE TABLE financial_operations (
 id uuid PRIMARY KEY, semantic_key text NOT NULL UNIQUE, kind text NOT NULL CHECK(kind IN ('checkout_payment','seller_transfer','policy_refund','technical_refund','recovery')),
 checkout_id uuid REFERENCES checkouts ON DELETE RESTRICT, payable_id uuid REFERENCES seller_payables ON DELETE RESTRICT,
 payment_attempt_id uuid REFERENCES payment_attempts ON DELETE RESTRICT, recovery_receivable_id uuid,
 expected_amount_cents bigint NOT NULL CHECK(expected_amount_cents>0), currency text NOT NULL CHECK(currency='EUR'), beneficiary_ref text,
 provider text NOT NULL, provider_scope text NOT NULL, parameters jsonb NOT NULL CHECK(jsonb_typeof(parameters)='object'), parameters_hash text NOT NULL,
 authorized_by uuid REFERENCES accounts ON DELETE RESTRICT, authority_snapshot jsonb, authorization_investigation_id uuid REFERENCES investigations ON DELETE RESTRICT,
 authorization_reason text, authorized_at timestamptz, created_at timestamptz NOT NULL,
 CHECK((kind='checkout_payment' AND checkout_id IS NOT NULL AND payable_id IS NULL AND payment_attempt_id IS NULL AND recovery_receivable_id IS NULL)
 OR (kind='seller_transfer' AND payable_id IS NOT NULL AND checkout_id IS NULL AND payment_attempt_id IS NULL AND recovery_receivable_id IS NULL AND beneficiary_ref IS NOT NULL)
 OR (kind IN ('policy_refund','technical_refund') AND payment_attempt_id IS NOT NULL AND checkout_id IS NULL AND payable_id IS NULL AND recovery_receivable_id IS NULL)
 OR (kind='recovery' AND recovery_receivable_id IS NOT NULL AND checkout_id IS NULL AND payable_id IS NULL AND payment_attempt_id IS NULL)),
 CHECK(kind NOT IN ('policy_refund','recovery') OR (authorized_by IS NOT NULL AND authority_snapshot IS NOT NULL AND authorization_investigation_id IS NOT NULL AND authorization_reason IS NOT NULL AND authorized_at IS NOT NULL)),
 UNIQUE(id,provider,provider_scope)
);
-- statement
ALTER TABLE payment_attempts ADD CONSTRAINT fk_payment_operation FOREIGN KEY(operation_id,provider,provider_scope) REFERENCES financial_operations(id,provider,provider_scope) DEFERRABLE INITIALLY DEFERRED;
-- statement
CREATE TABLE financial_operation_attempts (
 id uuid PRIMARY KEY, operation_id uuid NOT NULL, attempt_no integer NOT NULL CHECK(attempt_no>0), scheduled_run_id uuid REFERENCES scheduled_runs ON DELETE RESTRICT,
 provider text NOT NULL, provider_scope text NOT NULL, idempotency_key text NOT NULL,
 state text NOT NULL CHECK(state IN ('prepared','submitted','unknown','succeeded','failed','cancelled')),
 prepared_at timestamptz NOT NULL, submitted_at timestamptz, resolved_at timestamptz, provider_ref text, error_code text,
 claimed_by text, lease_until timestamptz, fencing_generation bigint NOT NULL DEFAULT 0 CHECK(fencing_generation>=0),
 UNIQUE(operation_id,attempt_no), UNIQUE(provider,provider_scope,idempotency_key), UNIQUE(provider,provider_scope,provider_ref),
 FOREIGN KEY(operation_id,provider,provider_scope) REFERENCES financial_operations(id,provider,provider_scope) ON DELETE RESTRICT,
 CHECK(state NOT IN ('succeeded','failed','cancelled') OR resolved_at IS NOT NULL)
);
-- statement
CREATE UNIQUE INDEX uq_operation_unresolved_attempt ON financial_operation_attempts(operation_id) WHERE state IN ('prepared','submitted','unknown');
-- statement
CREATE TABLE recovery_receivables (
 id uuid PRIMARY KEY, seller_id uuid NOT NULL REFERENCES accounts ON DELETE RESTRICT, investigation_id uuid NOT NULL REFERENCES investigations ON DELETE RESTRICT,
 original_transfer_operation_id uuid NOT NULL REFERENCES financial_operations ON DELETE RESTRICT,
 principal_cents bigint NOT NULL CHECK(principal_cents>0), currency text NOT NULL CHECK(currency='EUR'), created_at timestamptz NOT NULL
);
-- statement
ALTER TABLE financial_operations ADD CONSTRAINT fk_operation_receivable FOREIGN KEY(recovery_receivable_id) REFERENCES recovery_receivables ON DELETE RESTRICT;
-- statement
CREATE TABLE refund_allocations (
 id uuid PRIMARY KEY, operation_id uuid NOT NULL REFERENCES financial_operations ON DELETE RESTRICT, payment_attempt_id uuid NOT NULL REFERENCES payment_attempts ON DELETE RESTRICT,
 purchase_item_id uuid REFERENCES purchase_items ON DELETE RESTRICT, correction_kind text NOT NULL CHECK(correction_kind IN ('policy','duplicate_charge','technical')),
 principal_cents bigint NOT NULL CHECK(principal_cents>=0), buyer_fee_cents bigint NOT NULL CHECK(buyer_fee_cents>=0), seller_margin_cents bigint NOT NULL CHECK(seller_margin_cents>=0),
 CHECK(principal_cents::numeric+buyer_fee_cents::numeric+seller_margin_cents::numeric>0),
 CHECK(correction_kind<>'policy' OR (buyer_fee_cents=0 AND seller_margin_cents=0))
);
-- statement
CREATE TABLE investigation_evidence (
 investigation_id uuid NOT NULL REFERENCES investigations ON DELETE RESTRICT, private_object_id uuid NOT NULL REFERENCES private_objects ON DELETE RESTRICT,
 submitted_by uuid REFERENCES accounts ON DELETE RESTRICT, submitted_at timestamptz NOT NULL, PRIMARY KEY(investigation_id,private_object_id)
);
-- statement
CREATE TABLE testimonies (
 id uuid PRIMARY KEY, author_id uuid NOT NULL REFERENCES accounts ON DELETE RESTRICT, content text NOT NULL,
 moderation_state text NOT NULL DEFAULT 'pending' CHECK(moderation_state IN ('pending','approved','rejected')), moderated_by uuid REFERENCES accounts ON DELETE RESTRICT,
 moderated_at timestamptz, published_at timestamptz, CHECK(published_at IS NULL OR (moderation_state='approved' AND moderated_by IS NOT NULL AND moderated_at IS NOT NULL))
);
-- statement
CREATE TABLE notifications (
 id uuid PRIMARY KEY, recipient_id uuid NOT NULL REFERENCES accounts ON DELETE RESTRICT, event_key text NOT NULL, category text NOT NULL, payload_version integer NOT NULL CHECK(payload_version>0),
 private_payload jsonb NOT NULL CHECK(jsonb_typeof(private_payload)='object'), availability_event_id uuid REFERENCES availability_events ON DELETE RESTRICT,
 deadline timestamptz, created_at timestamptz NOT NULL, read_at timestamptz, delivery_state jsonb NOT NULL DEFAULT '{}' CHECK(jsonb_typeof(delivery_state)='object'),
 UNIQUE(recipient_id,event_key)
);
-- statement
CREATE INDEX ix_notification_recipient ON notifications(recipient_id,created_at,id);
-- statement
CREATE TABLE newsletter_subscriptions (
 id uuid PRIMARY KEY, email text NOT NULL, email_normalized text NOT NULL UNIQUE CHECK(btrim(email_normalized)<>''), subscribed boolean NOT NULL,
 quit_reason text, join_at timestamptz NOT NULL, quit_at timestamptz,
 CHECK((subscribed AND quit_at IS NULL AND quit_reason IS NULL) OR (NOT subscribed AND quit_at IS NOT NULL))
);
-- statement
CREATE TABLE provider_event_inbox (
 id uuid PRIMARY KEY, provider text NOT NULL, provider_scope text NOT NULL, event_ref text NOT NULL, received_at timestamptz NOT NULL,
 authenticated_at timestamptz NOT NULL, payload_version integer NOT NULL CHECK(payload_version>0), protected_payload jsonb NOT NULL,
 processing_state text NOT NULL CHECK(processing_state IN ('pending','processing','completed','failed')), next_attempt_at timestamptz, processed_at timestamptz,
 claimed_by text, lease_until timestamptz, fencing_generation bigint NOT NULL DEFAULT 0 CHECK(fencing_generation>=0), UNIQUE(provider,provider_scope,event_ref)
);
-- statement
ALTER TABLE payment_attempts ADD CONSTRAINT fk_payment_validation_event FOREIGN KEY(validation_event_id) REFERENCES provider_event_inbox ON DELETE RESTRICT;
-- statement
CREATE INDEX ix_inbox_pending ON provider_event_inbox(next_attempt_at,received_at,id) WHERE processing_state IN ('pending','failed');
-- statement
CREATE TABLE outbox_messages (
 id uuid PRIMARY KEY, effect_key text NOT NULL UNIQUE, aggregate_kind text NOT NULL, aggregate_id uuid NOT NULL, aggregate_version bigint NOT NULL CHECK(aggregate_version>0),
 event_type text NOT NULL, payload_version integer NOT NULL CHECK(payload_version>0), payload jsonb NOT NULL, created_at timestamptz NOT NULL,
 dispatched_at timestamptz, next_attempt_at timestamptz, claimed_by text, lease_until timestamptz, fencing_generation bigint NOT NULL DEFAULT 0 CHECK(fencing_generation>=0)
);
-- statement
CREATE INDEX ix_outbox_pending ON outbox_messages(next_attempt_at,created_at,id) WHERE dispatched_at IS NULL;
-- statement
CREATE TABLE consumed_effects (consumer text NOT NULL, effect_key text NOT NULL, completed_at timestamptz NOT NULL, PRIMARY KEY(consumer,effect_key));
-- statement
CREATE TABLE administrative_audit (
 id uuid PRIMARY KEY, actor_account_id uuid REFERENCES accounts ON DELETE RESTRICT, service_principal text, authority_snapshot jsonb NOT NULL,
 action text NOT NULL, subject_kind text NOT NULL, subject_id uuid NOT NULL, reason text, occurred_at timestamptz NOT NULL,
 outcome text NOT NULL, operation_id uuid REFERENCES financial_operations ON DELETE RESTRICT,
 CHECK(num_nonnulls(actor_account_id,service_principal)=1)
);
-- statement
CREATE INDEX ix_audit_subject ON administrative_audit(subject_kind,subject_id,occurred_at);
-- statement
CREATE INDEX ix_credentials_expiry ON account_credentials(expires_at);
-- statement
CREATE INDEX ix_private_object_expiry ON private_objects(delete_after) WHERE purged_at IS NULL;
-- statement
CREATE INDEX ix_investigation_open ON investigations(seller_id,reported_at) WHERE state IN ('open','acknowledged','investigating');
-- statement
-- statement
CREATE FUNCTION marketplace_freeze_columns() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE old_record jsonb; new_record jsonb; mutable_column text;
BEGIN
 IF TG_OP='DELETE' THEN
  RAISE EXCEPTION 'Protected history cannot be deleted from %', TG_TABLE_NAME USING ERRCODE='23514';
 END IF;
 old_record := to_jsonb(OLD); new_record := to_jsonb(NEW);
 FOREACH mutable_column IN ARRAY COALESCE(TG_ARGV, ARRAY[]::text[]) LOOP
  old_record := old_record - mutable_column; new_record := new_record - mutable_column;
 END LOOP;
 IF old_record IS DISTINCT FROM new_record THEN
  RAISE EXCEPTION 'Immutable fields changed in %', TG_TABLE_NAME USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END;
$$;
-- statement
CREATE FUNCTION marketplace_guard_private_redaction() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.private_redacted_at IS NOT NULL AND (
  NEW.private_redacted_at IS DISTINCT FROM OLD.private_redacted_at OR NEW.buyer_name IS NOT NULL OR NEW.buyer_email IS NOT NULL OR NEW.registration_fields IS NOT NULL
 ) THEN
  RAISE EXCEPTION 'Redacted snapshot cannot be restored' USING ERRCODE='23514';
 END IF;
 IF NEW.buyer_name IS DISTINCT FROM OLD.buyer_name OR NEW.buyer_email IS DISTINCT FROM OLD.buyer_email OR NEW.registration_fields IS DISTINCT FROM OLD.registration_fields THEN
  IF NOT (OLD.private_redacted_at IS NULL AND NEW.private_redacted_at IS NOT NULL AND NEW.buyer_name IS NULL AND NEW.buyer_email IS NULL AND NEW.registration_fields IS NULL) THEN
   RAISE EXCEPTION 'Private snapshot only permits complete retention redaction' USING ERRCODE='23514';
  END IF;
 END IF;
 RETURN NEW;
END;
$$;
-- statement
CREATE TRIGGER freeze_checkout_items BEFORE UPDATE OR DELETE ON checkout_items FOR EACH ROW EXECUTE FUNCTION marketplace_freeze_columns('protection_state','protected_until','reconciliation_state','released_at','buyer_name','buyer_email','registration_fields','private_redacted_at');
-- statement
CREATE TRIGGER guard_checkout_redaction BEFORE UPDATE ON checkout_items FOR EACH ROW EXECUTE FUNCTION marketplace_guard_private_redaction();
-- statement
CREATE TRIGGER freeze_checkout_intent BEFORE UPDATE OR DELETE ON checkouts FOR EACH ROW EXECUTE FUNCTION marketplace_freeze_columns('state','cancellation_requested_at','completed_at','version','claimed_by','lease_until','fencing_generation');
-- statement
CREATE TRIGGER freeze_operation_intent BEFORE UPDATE OR DELETE ON financial_operations FOR EACH ROW EXECUTE FUNCTION marketplace_freeze_columns();
-- statement
CREATE TRIGGER freeze_operation_attempt BEFORE UPDATE OR DELETE ON financial_operation_attempts FOR EACH ROW EXECUTE FUNCTION marketplace_freeze_columns('state','submitted_at','resolved_at','provider_ref','error_code','claimed_by','lease_until','fencing_generation');
-- statement
CREATE TRIGGER freeze_payment_attempt BEFORE UPDATE OR DELETE ON payment_attempts FOR EACH ROW EXECUTE FUNCTION marketplace_freeze_columns('state','validated_at','validation_event_id','last_reconciled_at','session_ref','payment_intent_ref','charge_ref');
-- statement
CREATE TRIGGER freeze_availability BEFORE UPDATE OR DELETE ON availability_events FOR EACH ROW EXECUTE FUNCTION marketplace_freeze_columns('invalidated_at','invalidation_reason');
-- statement
CREATE TRIGGER freeze_recipients BEFORE UPDATE OR DELETE ON availability_recipients FOR EACH ROW EXECUTE FUNCTION marketplace_freeze_columns();
-- statement
CREATE TRIGGER freeze_purchase BEFORE UPDATE OR DELETE ON purchases FOR EACH ROW EXECUTE FUNCTION marketplace_freeze_columns();
-- statement
CREATE TRIGGER freeze_purchase_item BEFORE UPDATE OR DELETE ON purchase_items FOR EACH ROW EXECUTE FUNCTION marketplace_freeze_columns('registration_update_state','registration_request_version','registration_requested_at','registration_applied_at','registration_evidence_object_id','registration_failure_code');
-- statement
CREATE TRIGGER freeze_ownership BEFORE UPDATE OR DELETE ON ownership_transitions FOR EACH ROW EXECUTE FUNCTION marketplace_freeze_columns();
-- statement
CREATE TRIGGER freeze_payable BEFORE UPDATE OR DELETE ON seller_payables FOR EACH ROW EXECUTE FUNCTION marketplace_freeze_columns('state','transferred_at','consecutive_failures','next_attempt_on','version');
-- statement
CREATE TRIGGER freeze_refund BEFORE UPDATE OR DELETE ON refund_allocations FOR EACH ROW EXECUTE FUNCTION marketplace_freeze_columns();
-- statement
CREATE TRIGGER freeze_recovery BEFORE UPDATE OR DELETE ON recovery_receivables FOR EACH ROW EXECUTE FUNCTION marketplace_freeze_columns();
-- statement
CREATE TRIGGER freeze_audit BEFORE UPDATE OR DELETE ON administrative_audit FOR EACH ROW EXECUTE FUNCTION marketplace_freeze_columns();
-- statement
ALTER TABLE checkout_items ADD CONSTRAINT uq_checkout_item_parent UNIQUE(id,checkout_id);
-- statement
ALTER TABLE purchases ADD CONSTRAINT uq_purchase_checkout_buyer UNIQUE(id,checkout_id,buyer_id);
-- statement
ALTER TABLE purchase_items ADD COLUMN checkout_id uuid NOT NULL;
-- statement
ALTER TABLE purchase_items ADD CONSTRAINT fk_purchase_item_parent FOREIGN KEY(purchase_id,checkout_id,buyer_id) REFERENCES purchases(id,checkout_id,buyer_id) ON DELETE RESTRICT;
-- statement
ALTER TABLE purchase_items ADD CONSTRAINT fk_purchase_item_checkout FOREIGN KEY(checkout_item_id,checkout_id) REFERENCES checkout_items(id,checkout_id) ON DELETE RESTRICT;
-- statement
ALTER TABLE purchase_items ADD CONSTRAINT uq_purchase_ownership_context UNIQUE(id,entitlement_id,ownership_sequence_no,buyer_id);
-- statement
ALTER TABLE ownership_transitions ADD CONSTRAINT fk_ownership_purchase_context FOREIGN KEY(purchase_item_id,entitlement_id,sequence_no,to_account_id) REFERENCES purchase_items(id,entitlement_id,ownership_sequence_no,buyer_id) ON DELETE RESTRICT;
-- statement
ALTER TABLE ownership_proofs ADD CONSTRAINT uq_proof_claim_context UNIQUE(id,entitlement_id,submitted_by);
-- statement
ALTER TABLE ownership_transitions ADD CONSTRAINT fk_ownership_claim_context FOREIGN KEY(claim_proof_id,entitlement_id,to_account_id) REFERENCES ownership_proofs(id,entitlement_id,submitted_by) ON DELETE RESTRICT;
-- statement
ALTER TABLE financial_operations ADD CONSTRAINT uq_refund_payment_context UNIQUE(id,payment_attempt_id);
-- statement
ALTER TABLE refund_allocations ADD CONSTRAINT fk_refund_payment_context FOREIGN KEY(operation_id,payment_attempt_id) REFERENCES financial_operations(id,payment_attempt_id) ON DELETE RESTRICT;
-- statement
CREATE FUNCTION marketplace_guard_listing_publication() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.state='published' AND NOT EXISTS (
  SELECT 1 FROM ownership_proofs p JOIN bib_entitlements e ON e.id=NEW.entitlement_id
  WHERE p.id=NEW.proof_id AND p.race_id=NEW.race_id AND p.submitted_by=NEW.seller_id
   AND p.entitlement_id=NEW.entitlement_id AND p.decision='approved' AND p.reviewed_version=NEW.approved_proof_version
   AND p.proof_version=NEW.approved_proof_version AND e.current_owner_account_id=NEW.seller_id
 ) THEN
  RAISE EXCEPTION 'Published listing requires approved proof and current ownership' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END;
$$;
-- statement
CREATE TRIGGER guard_listing_publication BEFORE INSERT OR UPDATE ON resale_listings FOR EACH ROW EXECUTE FUNCTION marketplace_guard_listing_publication();
-- statement
CREATE FUNCTION marketplace_guard_terminal_protection() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.protection_state IN ('released','consumed') AND NEW.protection_state IS DISTINCT FROM OLD.protection_state THEN
  RAISE EXCEPTION 'Terminal checkout protection cannot be reactivated' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END;
$$;
-- statement
CREATE TRIGGER guard_terminal_protection BEFORE UPDATE ON checkout_items FOR EACH ROW EXECUTE FUNCTION marketplace_guard_terminal_protection();
-- statement
ALTER TABLE financial_operations ADD CONSTRAINT uq_payment_operation_checkout UNIQUE(id,checkout_id,provider,provider_scope);
-- statement
ALTER TABLE payment_attempts ADD CONSTRAINT fk_payment_operation_checkout FOREIGN KEY(operation_id,checkout_id,provider,provider_scope) REFERENCES financial_operations(id,checkout_id,provider,provider_scope) DEFERRABLE INITIALLY DEFERRED;
-- statement
ALTER TABLE provider_event_inbox ADD CONSTRAINT uq_provider_event_context UNIQUE(id,provider,provider_scope);
-- statement
ALTER TABLE payment_attempts ADD CONSTRAINT fk_payment_validation_context FOREIGN KEY(validation_event_id,provider,provider_scope) REFERENCES provider_event_inbox(id,provider,provider_scope) ON DELETE RESTRICT;
