-- Run with psql as the schema owner, after migrations, against the intended database.
-- Supply an existing non-owner runtime role: -v runtime_role=runnerbib_runtime
-- Role creation/password provisioning belongs to deployment secret management.
-- Abort on unsafe roles and roll back all privilege changes on failure.
\set ON_ERROR_STOP on
BEGIN;
SELECT set_config('runnerbib.runtime_role', :'runtime_role', true);
REVOKE ALL ON SCHEMA public FROM PUBLIC;
REVOKE CREATE ON SCHEMA public FROM :"runtime_role";
DO $$
DECLARE runtime_oid oid; runtime_name text := current_setting('runnerbib.runtime_role');
BEGIN
 SELECT oid INTO runtime_oid FROM pg_roles WHERE rolname=runtime_name;
 IF runtime_oid IS NULL THEN
  RAISE EXCEPTION 'Runtime role % does not exist; provision a dedicated non-owner role first', runtime_name;
 END IF;
 IF EXISTS (
  SELECT 1 FROM pg_roles r WHERE pg_has_role(runtime_oid,r.oid,'MEMBER')
   AND (r.rolsuper OR r.rolcreaterole OR r.rolcreatedb OR r.rolreplication OR r.rolbypassrls)
 ) THEN
  RAISE EXCEPTION 'Runtime role % has elevated attributes or membership; use a dedicated non-owner, non-superuser role without elevated memberships', runtime_name;
 END IF;
 IF EXISTS (
  SELECT 1 FROM pg_roles r WHERE pg_has_role(runtime_oid,r.oid,'MEMBER') AND (
   EXISTS (SELECT 1 FROM pg_namespace n WHERE n.nspname !~ '^pg_' AND n.nspname<>'information_schema'
    AND has_schema_privilege(r.oid,n.oid,'CREATE'))
   OR has_database_privilege(r.oid,current_database(),'CREATE')
  )
 ) THEN
  RAISE EXCEPTION 'Runtime role % retains direct/inherited CREATE privileges; revoke database CREATE or remove privileged memberships before retrying', runtime_name;
 END IF;
 IF EXISTS (
  SELECT 1 FROM pg_database WHERE datname=current_database() AND pg_has_role(runtime_oid,datdba,'MEMBER')
 ) OR EXISTS (
  SELECT 1 FROM pg_namespace WHERE nspname !~ '^pg_' AND nspname<>'information_schema' AND pg_has_role(runtime_oid,nspowner,'MEMBER')
 ) OR EXISTS (
  SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
   WHERE n.nspname !~ '^pg_' AND n.nspname<>'information_schema' AND pg_has_role(runtime_oid,c.relowner,'MEMBER')
 ) OR EXISTS (
  SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
   WHERE n.nspname !~ '^pg_' AND n.nspname<>'information_schema' AND pg_has_role(runtime_oid,p.proowner,'MEMBER')
 ) THEN
  RAISE EXCEPTION 'Runtime role % owns database/schema objects or can assume an owner role; transfer ownership or remove owner memberships before retrying', runtime_name;
 END IF;
END;
$$;
GRANT USAGE ON SCHEMA public TO :"runtime_role";
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM :"runtime_role";
GRANT SELECT ON ALL TABLES IN SCHEMA public TO :"runtime_role";
GRANT INSERT, UPDATE ON TABLE accounts, account_private_profiles, account_credentials, organizations, organization_memberships, races, private_objects, eligibility_imports, bib_entitlements, ownership_proofs, resale_listings, race_waitlist_memberships, availability_events, availability_recipients, cart_items, checkouts, checkout_items, buyer_race_guards, seller_accounts, seller_onboarding_operations, payment_attempts, purchases, purchase_items, ownership_transitions, seller_payables, investigations, payable_holds, seller_transfer_suspensions, scheduled_runs, financial_operations, financial_operation_attempts, recovery_receivables, refund_allocations, investigation_evidence, testimonies, notifications, newsletter_subscriptions, provider_event_inbox, outbox_messages, consumed_effects, administrative_audit TO :"runtime_role";
GRANT DELETE ON TABLE account_private_profiles, account_credentials, cart_items, notifications, newsletter_subscriptions TO :"runtime_role";
-- Immutable tables permit insert/read; targeted triggers additionally protect snapshots.
REVOKE UPDATE ON TABLE financial_operations, ownership_transitions, purchases, availability_recipients, administrative_audit, refund_allocations, recovery_receivables FROM :"runtime_role";
-- Local queue workers acknowledge/delete only technical transport rows.
-- The forward queue migration may not yet be installed when granting core privileges.
DO $$
BEGIN
 IF to_regclass('public.messenger_messages') IS NOT NULL THEN
  EXECUTE format('GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE messenger_messages TO %I', current_setting('runnerbib.runtime_role'));
  EXECUTE format('GRANT USAGE, SELECT ON SEQUENCE messenger_messages_id_seq TO %I', current_setting('runnerbib.runtime_role'));
 END IF;
END;
$$;
-- No migration metadata writes, CREATE privilege or DDL ownership is granted.
-- Direct revocation does not remove grants held by assumable parent roles.
DO $$
DECLARE runtime_oid oid; runtime_name text := current_setting('runnerbib.runtime_role');
BEGIN
 SELECT oid INTO runtime_oid FROM pg_roles WHERE rolname=runtime_name;
 IF EXISTS (
  SELECT 1 FROM pg_roles r WHERE pg_has_role(runtime_oid,r.oid,'MEMBER') AND (
   has_table_privilege(r.oid,to_regclass('public.doctrine_migration_versions'),'INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER')
   OR has_any_column_privilege(r.oid,to_regclass('public.doctrine_migration_versions'),'INSERT,UPDATE,REFERENCES')
  )
 ) THEN
  RAISE EXCEPTION 'Runtime role % retains inherited migration metadata writes; remove privileged memberships or revoke those parent grants before retrying', runtime_name;
 END IF;
 IF EXISTS (
  SELECT 1 FROM pg_roles r CROSS JOIN pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
   WHERE pg_has_role(runtime_oid,r.oid,'MEMBER') AND n.nspname='public' AND c.relkind IN ('r','p')
    AND has_table_privilege(r.oid,c.oid,'TRUNCATE,TRIGGER')
 ) THEN
  RAISE EXCEPTION 'Runtime role % retains inherited TRUNCATE/TRIGGER privileges; remove privileged memberships or revoke those parent grants before retrying', runtime_name;
 END IF;
END;
$$;
COMMIT;
