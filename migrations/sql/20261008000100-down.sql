DROP TABLE administrative_audit, consumed_effects, outbox_messages, provider_event_inbox, newsletter_subscriptions, notifications, testimonies, investigation_evidence, refund_allocations, recovery_receivables, financial_operation_attempts, financial_operations, scheduled_runs, seller_transfer_suspensions, payable_holds, investigations, seller_payables, ownership_transitions, purchase_items, purchases, payment_attempts, seller_accounts, buyer_race_guards, checkout_items, checkouts, cart_items, availability_recipients, availability_events, race_waitlist_memberships, resale_listings, ownership_proofs, bib_entitlements, eligibility_imports, private_objects, races, organization_memberships, organizations, account_credentials, account_private_profiles, accounts;
-- statement
DROP FUNCTION marketplace_guard_private_redaction();
-- statement
DROP FUNCTION marketplace_freeze_columns();
-- statement
DROP FUNCTION marketplace_guard_listing_publication();
-- statement
DROP FUNCTION marketplace_guard_terminal_protection();
