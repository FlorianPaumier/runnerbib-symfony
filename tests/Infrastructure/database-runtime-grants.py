"""Execute the public grants script against isolated real PostgreSQL fixtures."""

from pathlib import Path
import secrets
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]
COMPOSE = ["docker", "compose", "--env-file", ".env.docker", "exec", "-T"]
SCRIPT = (ROOT / "tools/database-runtime-grants.sql").read_text()


class RuntimeGrantsTest(unittest.TestCase):
    def psql(self, sql, database=None, role="", check=True):
        result = subprocess.run(
            COMPOSE + ["database", "sh", "-c",
                       'exec psql -X -q -At -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$1" -v "runtime_role=$2"',
                       "grant-fixture", database or "postgres", role],
            input=sql, text=True, cwd=ROOT, capture_output=True, timeout=45,
        )
        if check:
            self.assertEqual(0, result.returncode, result.stderr)
        return result

    def setUp(self):
        self.database = "grants_test_" + secrets.token_hex(6)
        self.runtime = self.database + "_runtime"
        self.parent = self.database + "_parent"
        self.child = self.database + "_child"
        self.owner = self.database + "_owner"
        self.unsafe = self.database + "_unsafe"
        self.roles = [self.runtime, self.parent, self.child, self.owner, self.unsafe]
        self.addCleanup(self.clean_up)
        self.psql("CREATE DATABASE " + self.database + ";" + "".join(
            "CREATE ROLE " + role + " NOLOGIN" + (" NOINHERIT" if role == self.child else "") + ";"
            for role in self.roles
        ) + "GRANT " + self.parent + " TO " + self.child + ";")
        php = r'''
require 'vendor/autoload.php';
$original = getenv('DATABASE_URL');
if (!is_string($original) || !preg_match('/^grants_test_[a-f0-9]{12}$/D', $argv[1])) {
    throw new RuntimeException('An explicit bootstrap URL and isolated fixture database are required.');
}
$url = preg_replace('~/[^/?]+(?=\?|$)~', '/'.$argv[1], $original, 1, $replacements);
if (!is_string($url) || 1 !== $replacements) {
    throw new RuntimeException('Cannot derive the isolated fixture migration URL.');
}
$process = new Symfony\Component\Process\Process(['php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction'], '/app', ['DATABASE_URL'=>$url, 'APP_ENV'=>'test']);
$process->mustRun();
echo "Fixture migrations completed\n";
'''
        result = subprocess.run(COMPOSE + ["-e", "APP_ENV=test", "php", "php", "-r", php, self.database],
                                cwd=ROOT, text=True, capture_output=True, timeout=90)
        self.assertEqual(0, result.returncode, result.stderr)

    def clean_up(self):
        dropped = self.psql("DROP DATABASE IF EXISTS " + self.database + " WITH (FORCE);", check=False)
        roles = self.psql("DROP ROLE IF EXISTS " + ",".join(self.roles) + ";", check=False)
        self.assertEqual(0, dropped.returncode, dropped.stderr)
        self.assertEqual(0, roles.returncode, roles.stderr)

    def apply(self, role=None, check=True):
        return self.psql(SCRIPT, self.database, role or self.runtime, check)

    def runtime_sql(self, sql, check=True):
        return self.psql("SET ROLE " + self.runtime + ";" + sql, self.database, check=check)

    def test_safe_runtime_crud_queue_replay_and_financial_write_denials(self):
        self.psql("GRANT CREATE ON SCHEMA public TO " + self.runtime + ";", self.database)
        self.apply()
        self.apply()
        self.assertEqual("f", self.psql("SELECT has_schema_privilege('" + self.runtime + "','public','CREATE');", self.database).stdout.strip())
        self.runtime_sql("INSERT INTO accounts(id,terms_version,terms_accepted_at) VALUES('00000000-0000-0000-0000-000000000001','fixture',CURRENT_TIMESTAMP); UPDATE accounts SET optional_email_enabled=true; INSERT INTO account_private_profiles(account_id,email,email_normalized) VALUES('00000000-0000-0000-0000-000000000001','fixture@example.test','fixture@example.test'); DELETE FROM account_private_profiles;")
        self.assertEqual("t", self.runtime_sql("SELECT optional_email_enabled FROM accounts;").stdout.strip())
        self.runtime_sql("INSERT INTO messenger_messages(body,headers,queue_name,created_at,available_at) VALUES('fixture','{}','async',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP); UPDATE messenger_messages SET delivered_at=CURRENT_TIMESTAMP; DELETE FROM messenger_messages;")
        self.assertEqual("0", self.runtime_sql("SELECT count(*) FROM messenger_messages;").stdout.strip())
        self.runtime_sql("INSERT INTO checkouts(id,buyer_id,state,authorized_at,initial_deadline,expected_total_cents,currency) VALUES('00000000-0000-0000-0000-000000000002','00000000-0000-0000-0000-000000000001','pending',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP+INTERVAL '15 minutes',10500,'EUR'); INSERT INTO financial_operations(id,semantic_key,kind,checkout_id,expected_amount_cents,currency,provider,provider_scope,parameters,parameters_hash,created_at) VALUES('00000000-0000-0000-0000-000000000003','fixture:payment','checkout_payment','00000000-0000-0000-0000-000000000002',10500,'EUR','stripe','test:fixture','{}','fixture',CURRENT_TIMESTAMP);")
        statements = [
            "CREATE TABLE unauthorized(id int)", "ALTER TABLE races ADD COLUMN unauthorized text",
            "DROP TABLE notifications", "ALTER TABLE races DISABLE TRIGGER ALL", "TRUNCATE accounts CASCADE",
            "INSERT INTO doctrine_migration_versions(version) VALUES('unauthorized')",
            "UPDATE doctrine_migration_versions SET version='unauthorized'", "DELETE FROM doctrine_migration_versions",
            "UPDATE financial_operations SET expected_amount_cents=1", "DELETE FROM financial_operations",
            "UPDATE purchases SET total_cents=1", "UPDATE ownership_transitions SET kind='verified_claim'",
        ]
        for statement in statements:
            with self.subTest(statement=statement):
                denied = self.runtime_sql(statement, check=False)
                self.assertNotEqual(0, denied.returncode, "Unexpectedly permitted: " + statement)
                self.assertRegex(denied.stderr, "permission denied|must be owner")
        self.assertEqual("10500", self.psql("SELECT expected_amount_cents FROM financial_operations;", self.database).stdout.strip())
        self.assertEqual("1", self.psql("SELECT count(*) FROM accounts;", self.database).stdout.strip())

    def test_current_seller_onboarding_operation_can_be_inserted_and_reconciled(self):
        self.apply()
        self.runtime_sql("INSERT INTO accounts(id,terms_version,terms_accepted_at) VALUES('00000000-0000-0000-0000-000000000001','fixture',CURRENT_TIMESTAMP);")
        self.runtime_sql("INSERT INTO seller_onboarding_operations(id,seller_id,provider_scope,provider,idempotency_key,parameters,parameters_hash,state,created_at) VALUES('00000000-0000-0000-0000-000000000002','00000000-0000-0000-0000-000000000001','test:fixture','stripe','fixture:onboarding','{}','fixture','prepared',CURRENT_TIMESTAMP);")
        self.runtime_sql("UPDATE seller_onboarding_operations SET state='unknown',submitted_at=CURRENT_TIMESTAMP;")
        self.assertEqual("unknown", self.runtime_sql("SELECT state FROM seller_onboarding_operations;").stdout.strip())
        rewrite = self.runtime_sql("UPDATE seller_onboarding_operations SET idempotency_key='unauthorized-rewrite';", check=False)
        self.assertNotEqual(0, rewrite.returncode)
        self.assertIn("Seller onboarding intent is immutable", rewrite.stderr)
        deleted = self.runtime_sql("DELETE FROM seller_onboarding_operations;", check=False)
        self.assertNotEqual(0, deleted.returncode)
        self.assertIn("permission denied", deleted.stderr)
        self.assertEqual("fixture:onboarding", self.runtime_sql("SELECT idempotency_key FROM seller_onboarding_operations;").stdout.strip())

    def test_elevated_owner_and_noinherit_memberships_are_rejected(self):
        for attribute in ["SUPERUSER", "CREATEDB", "CREATEROLE", "REPLICATION", "BYPASSRLS"]:
            with self.subTest(attribute=attribute):
                self.psql("ALTER ROLE " + self.unsafe + " NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS; ALTER ROLE " + self.unsafe + " " + attribute + ";")
                self.assertNotEqual(0, self.apply(self.unsafe, check=False).returncode)
        self.psql("ALTER ROLE " + self.unsafe + " NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS; ALTER ROLE " + self.parent + " CREATEDB;")
        self.assertNotEqual(0, self.apply(self.child, check=False).returncode)
        self.psql("ALTER ROLE " + self.parent + " NOCREATEDB; GRANT CREATE ON SCHEMA public TO " + self.parent + ";", self.database)
        self.assertEqual("f", self.psql("SELECT has_schema_privilege('" + self.child + "','public','CREATE');", self.database).stdout.strip())
        self.assertNotEqual(0, self.apply(self.child, check=False).returncode)
        self.psql("REVOKE CREATE ON SCHEMA public FROM " + self.parent + "; ALTER TABLE races OWNER TO " + self.owner + ";", self.database)
        self.assertIn("owns database/schema objects", self.apply(self.owner, check=False).stderr)
        self.psql("GRANT " + self.owner + " TO " + self.child + ";")
        self.assertNotEqual(0, self.apply(self.child, check=False).returncode)

    def test_inherited_metadata_truncate_trigger_and_database_create_fail_transactionally(self):
        self.psql("GRANT INSERT ON doctrine_migration_versions TO " + self.parent + "; GRANT CREATE ON SCHEMA public TO PUBLIC;", self.database)
        rejected = self.apply(self.child, check=False)
        self.assertNotEqual(0, rejected.returncode)
        self.assertIn("migration metadata", rejected.stderr)
        self.assertEqual("t", self.psql("SELECT has_schema_privilege('" + self.runtime + "','public','CREATE');", self.database).stdout.strip())
        self.assertEqual("f", self.psql("SELECT has_table_privilege('" + self.child + "','accounts','INSERT');", self.database).stdout.strip())
        self.psql("REVOKE CREATE ON SCHEMA public FROM PUBLIC; REVOKE INSERT ON doctrine_migration_versions FROM " + self.parent + ";", self.database)
        for privilege in ["TRUNCATE", "TRIGGER"]:
            self.psql("GRANT " + privilege + " ON races TO " + self.parent + ";", self.database)
            self.assertNotEqual(0, self.apply(self.child, check=False).returncode)
            self.psql("REVOKE " + privilege + " ON races FROM " + self.parent + ";", self.database)
        self.psql("GRANT CREATE ON DATABASE " + self.database + " TO " + self.runtime + ";", self.database)
        self.assertNotEqual(0, self.apply(check=False).returncode)


if __name__ == "__main__":
    unittest.main()
