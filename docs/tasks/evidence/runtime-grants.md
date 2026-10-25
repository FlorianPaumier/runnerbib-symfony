# Runtime grants — privilege regression fix

Executed 2026-10-08. Changes are limited to `tools/database-runtime-grants.sql`. BASE-06 remains incomplete: no Cloud role, resource or production deployment was provisioned or verified.

## Red → green → review

The public interface under test is the grant script run by `psql -X -v ON_ERROR_STOP=1 -v runtime_role=<role>` against real PostgreSQL 17. A temporary Python subprocess harness at `/tmp/runnerbib-grants-check.py` invoked the existing development database container. It created fresh random `grantcheck_<hex>` databases and corresponding NOLOGIN roles, installed the existing migration SQL and a migration-bookkeeping fixture, and dropped only those named fixtures in `finally`. It did not run the grant script against the application database.

**Observed red:** `python3 /tmp/runnerbib-grants-check.py` against the original script exited 1 with `AssertionError: RED: direct CREATE survived script: t`. The role was explicitly granted `CREATE ON SCHEMA public` before script execution. Independent expectation was `has_schema_privilege(role,'public','CREATE') = false`; actual result was true. Setup and script execution succeeded; failure was the unsafe retained privilege.

**Implementation:** the script now revokes direct public-schema CREATE, aborts on elevated role attributes/memberships, direct/inherited database or application-schema CREATE, and database/application-object ownership. Membership checks also detect NOINHERIT roles that could assume privileged parent roles. Errors tell the operator to remove privileged memberships/grants or transfer ownership. `ON_ERROR_STOP`, BEGIN and COMMIT ensure any rejection rolls back all privilege changes rather than leaving partial grants/revokes.

**Observed green:** the same harness passed. The expanded harness then passed before and after final review of the ownership/schema scope. Final execution used prefix `grantcheck_ed558fdd15` and exited 0 with `ALL CHECKS PASSED`:

- Existing direct public CREATE was removed; replay of the script succeeded.
- A NOINHERIT child with membership in a CREATE-capable parent was rejected.
- A superuser role was rejected with an actionable error.
- An ordinary role with database CREATE was rejected.
- On an inherited-privilege rejection, the initial PUBLIC CREATE revocation was rolled back, verified through an independent privilege query.
- An ordinary role owning `races` was rejected.
- The safe runtime role could SELECT races, INSERT an account and UPDATE its email preference.
- The runtime role could not CREATE TABLE, INSERT migration bookkeeping, disable race triggers or DROP races.

Each forbidden statement was executed with `SET ROLE <safe-runtime-role>` and required a nonzero psql exit. Successful behavior used real writes in the isolated database, not mocked privileges. Final read-only queries against `pg_database` and `pg_roles` found no `grantcheck_%` fixtures left. `git diff --check` passed.

## Operator usage and limits

Use the script as the schema owner with an existing dedicated runtime role after migrations:

```sh
psql -X --set=ON_ERROR_STOP=1 --set=runtime_role=runnerbib_runtime \
  --file=tools/database-runtime-grants.sql
```

Supply the intended database connection through deployment secrets or standard PostgreSQL connection environment variables. Role passwords and role creation remain outside this script. The check covers the connected database's application schemas; it does not audit other databases in the cluster, SECURITY DEFINER application functions or future grants made after the script runs. System schemas are excluded from application-object ownership checks. No claim is made that ordinary runtime SQL authorization replaces business permission checks.

The temporary harness was an execution aid, not a committed regression suite. Preserve these scenarios when implementing BASE-06's deployment-role tests. No Docker configuration/image changed, so no image builds were required for this SQL-only fix; the grant script and its rejection paths were exercised against the running PostgreSQL service. PHP runtime coverage is unaffected.

## Follow-up hardening, 2026-10-09

The same isolated-role harness observed another red: after revoking parent schema CREATE and granting only INSERT on migration bookkeeping to the parent, the NOINHERIT child passed the old script. The independent check failed with `RED: inherited migration metadata writes accepted`.

The script now verifies effective table/column bookkeeping write privileges across all assumable memberships, and rejects effective TRUNCATE/TRIGGER privileges on public application tables. These checks run before COMMIT, preserving atomic rejection. Re-run passed inherited metadata-write, TRUNCATE and TRIGGER rejection, along with all earlier cases. It also passed runtime insert/update/acknowledgement deletion on the new technical messenger table. Queue CRUD and sequence usage are granted only if that forward migration exists; no blanket DELETE/TRUNCATE grant was introduced.

Final isolated prefix `grantcheck_a94268d72a` exited 0 with `ALL CHECKS PASSED`. Only temporary fixture roles/databases were changed and cleaned up. No production role was created or modified; BASE-06's Cloud deployment acceptance is still pending.

## Permanent real-PostgreSQL regression, 2026-10-12

Saved `tests/Infrastructure/database-runtime-grants.py`, using only host Python standard library and actual Docker `psql`. Run `python3 tests/Infrastructure/database-runtime-grants.py` from the Symfony project with the development Compose database/PHP services running and bootstrap migration credentials available. Every case creates a unique `grants_test_<random>` database and only uniquely named fixture roles, applies all actual current Doctrine migrations through the PHP container, and runs the public grants SQL through `psql` against that isolated database. Cleanup drops only those exact fixture database/role names, including on failure; no application database, independent project or credential is reset.

Observed complete green: 4 unittest cases passed in 24 seconds. These verify safe account/profile CRUD, technical queue identity/insert/update/ack deletion, script replay, and actual refused CREATE/ALTER/DROP/trigger disabling/TRUNCATE, migration-bookkeeping writes and immutable financial updates/deletes. Independent queries preserve account count and the financial operation's 10,500-cent amount. Role tests reject every elevated attribute, a direct table owner, inherited owner membership, a genuine NOINHERIT child's assumable elevated/CREATE parent, inherited metadata/TRUNCATE/TRIGGER rights and direct database CREATE. A rejected script restores the PUBLIC schema privilege changed before its failure and leaves the child without partial account INSERT grants.

The first draft harness had invalid onboarding/purchase column names, redundant ALTER ROLE attribute options and overly narrow permission-message expectations. Those setup/expectation defects were corrected before any missing-policy red was claimed. The genuine new red then applied the script successfully, inserted an account under the runtime role and received `permission denied for table seller_onboarding_operations` for its valid current-schema insert. The minimal script fix adds only that existing forward table to INSERT/UPDATE grants. No financial UPDATE revocation or DELETE policy was broadened. The complete suite then passed; an additional focused onboarding run also passed after verifying reconciliation to `unknown`, refusal to rewrite the immutable idempotency key, refusal to delete the operation and preservation of its original key.

Fixture roles are NOLOGIN; statements use actual PostgreSQL SET ROLE privilege enforcement, not mocked permission rows. This validates SQL authorization and transactional grant refusal, not production authentication or Cloud provisioning. The integrator owns actual restricted-role credential provisioning, login/runtime smoke and production reapplication after all migrations. Previous system-schema/SECURITY DEFINER/future-grant limitations still apply. No new dependency, table or privilege-management wrapper was added; PHP src coverage is unaffected.
