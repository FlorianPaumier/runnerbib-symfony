-- Run with psql as the schema owner, after migrations, against the intended database.
-- Supply an existing non-owner runtime role: -v runtime_role=runnerbib_runtime
-- Role creation/password provisioning belongs to deployment secret management.
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO :"runtime_role";
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM :"runtime_role";
GRANT SELECT ON ALL TABLES IN SCHEMA public TO :"runtime_role";
GRANT INSERT, UPDATE ON TABLE accounts, account_private_profiles, account_credentials, organizations, organization_memberships, races, private_objects, eligibility_imports, bib_entitlements, ownership_proofs, resale_listings, race_waitlist_memberships, availability_events, availability_recipients, cart_items, checkouts, checkout_items, buyer_race_guards, seller_accounts, payment_attempts, purchases, purchase_items, ownership_transitions, seller_payables, investigations, payable_holds, seller_transfer_suspensions, scheduled_runs, financial_operations, financial_operation_attempts, recovery_receivables, refund_allocations, investigation_evidence, testimonies, notifications, newsletter_subscriptions, provider_event_inbox, outbox_messages, consumed_effects, administrative_audit TO :"runtime_role";
GRANT DELETE ON TABLE account_private_profiles, account_credentials, cart_items, notifications, newsletter_subscriptions TO :"runtime_role";
-- Immutable tables permit insert/read; targeted triggers additionally protect snapshots.
REVOKE UPDATE ON TABLE financial_operations, ownership_transitions, purchases, availability_recipients, administrative_audit, refund_allocations, recovery_receivables FROM :"runtime_role";
-- No migration metadata writes, CREATE privilege or DDL ownership is granted.
