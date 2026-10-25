"""Read-only checks of the production Compose credential boundary."""

import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import unittest
from urllib.parse import urlparse


ROOT = Path(__file__).resolve().parents[2]


class ProductionComposeTest(unittest.TestCase):
    def compose(self, runtime_url=None, privacy_lifetime=None):
        values = {
            "APP_SECRET": "compose-validation-only",
            "NEWSLETTER_SIGNING_SECRET": "newsletter-validation-only",
            "POSTGRES_USER": "bootstrap_fixture",
            "POSTGRES_DB": "fixture",
            "POSTGRES_PASSWORD": "owner-validation-only",
            "REDIS_PASSWORD": "redis-validation-only",
            "REDIS_URL": "redis://:redis-validation-only@redis:6379",
            "MAILER_DSN": "null://null",
            "MAILER_FROM": "no-reply@example.test",
            "APP_BASE_URL": "https://example.test",
        }
        if runtime_url is not None:
            values["RUNTIME_DATABASE_URL"] = runtime_url
        if privacy_lifetime is not None:
            values["PRIVACY_PROGRESS_LIFETIME_SECONDS"] = privacy_lifetime
        variables = set(re.findall(r"\$\{([A-Z_0-9]+)", (ROOT / "compose.prod.yaml").read_text()))
        variables.add("RUNTIME_DATABASE_URL")
        environment = {key: value for key, value in os.environ.items() if key not in variables}
        with tempfile.TemporaryDirectory(prefix="runnerbib-compose-") as directory:
            fixture = Path(directory) / "fixture.env"
            fixture.write_text("".join(f"{key}={value}\n" for key, value in values.items()))
            return subprocess.run(
                ["docker", "compose", "--env-file", str(fixture), "-f", "compose.prod.yaml", "config", "--format", "json"],
                cwd=ROOT, env=environment, text=True, capture_output=True, timeout=30,
            )

    def test_bootstrap_credentials_cannot_silently_become_runtime_credentials(self):
        result = self.compose()
        self.assertNotEqual(0, result.returncode, "Production accepted only bootstrap credentials")
        self.assertIn("RUNTIME_DATABASE_URL", result.stderr)

    def test_runtime_credentials_are_independent_and_database_stays_private(self):
        runtime_url = "postgresql://runtime_fixture:runtime-validation-only@database:5432/fixture?serverVersion=17"
        result = self.compose(runtime_url)
        self.assertEqual(0, result.returncode, result.stderr)
        services = json.loads(result.stdout)["services"]
        self.assertEqual(runtime_url, services["php"]["environment"]["DATABASE_URL"])
        self.assertNotEqual(
            services["database"]["environment"]["POSTGRES_USER"],
            urlparse(services["php"]["environment"]["DATABASE_URL"]).username,
        )
        self.assertFalse(services["database"].get("ports"))
        self.assertFalse(services["redis"].get("ports"))

    def test_production_supervises_queue_and_scheduled_work_with_runtime_credentials(self):
        runtime_url = "postgresql://runtime_fixture:runtime-validation-only@database:5432/fixture"
        result = self.compose(runtime_url)
        self.assertEqual(0, result.returncode, result.stderr)
        services = json.loads(result.stdout)["services"]
        self.assertIn("worker", services)
        self.assertIn("scheduler", services)
        for name in ("worker", "scheduler"):
            service = services[name]
            self.assertEqual(runtime_url, service["environment"]["DATABASE_URL"])
            self.assertEqual("unless-stopped", service["restart"])
            self.assertFalse(service.get("ports"))
            self.assertTrue(service["healthcheck"]["disable"])
            self.assertEqual("prod", service["environment"]["APP_ENV"])
        self.assertIn("messenger:consume", services["worker"]["command"])
        self.assertEqual(["/app/docker/php/scheduler.sh"], services["scheduler"]["command"])
        self.assertEqual("SIGTERM", services["scheduler"].get("stop_signal"))

    def test_privacy_expiry_is_forwarded_without_inventing_a_policy_default(self):
        for lifetime in (None, "3600"):
            with self.subTest(lifetime=lifetime):
                result = self.compose("postgresql://runtime_fixture:runtime-validation-only@database:5432/fixture", lifetime)
                self.assertEqual(0, result.returncode, result.stderr)
                services = json.loads(result.stdout)["services"]
                for name in ("php", "worker", "scheduler"):
                    self.assertEqual(lifetime or "", services[name]["environment"].get("PRIVACY_PROGRESS_LIFETIME_SECONDS"))


if __name__ == "__main__":
    unittest.main()
