"""Check the full-suite budget and preserve test failures at the Make boundary."""

import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class TestRunnerBudgetTest(unittest.TestCase):
    def run_target(self, command, budget, environment=None):
        return subprocess.run(
            ['make', '--no-print-directory', 'test', f'DC={command}', f'TEST_MAX_SECONDS={budget}'],
            cwd=ROOT, env=environment, capture_output=True, text=True, check=False,
        )

    def test_successful_suite_inside_budget_passes(self):
        result = self.run_target('true', '120')
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_slow_successful_suite_fails_the_budget(self):
        with tempfile.TemporaryDirectory(prefix='runnerbib-budget-') as directory:
            clock = Path(directory) / 'date'
            clock.write_text('#!/bin/sh\nif [ -f "$BUDGET_CLOCK_STARTED" ]; then echo 221; else touch "$BUDGET_CLOCK_STARTED"; echo 100; fi\n')
            clock.chmod(0o700)
            environment = dict(os.environ, PATH=directory + os.pathsep + os.environ['PATH'],
                               BUDGET_CLOCK_STARTED=str(Path(directory) / 'started'))
            result = self.run_target('true', '120', environment)
        self.assertNotEqual(0, result.returncode)
        self.assertIn('Test suite wall time: 121s', result.stdout)
        self.assertIn('Test suite exceeded', result.stdout + result.stderr)

    def test_failed_suite_stays_failed(self):
        with tempfile.TemporaryDirectory(prefix='runnerbib-failed-suite-') as directory:
            command = Path(directory) / 'docker'
            command.write_text('#!/bin/sh\ncase "$*" in *exec*) exit 7;; esac\n')
            command.chmod(0o700)
            result = self.run_target(str(command), '120')
        self.assertNotEqual(0, result.returncode)
        self.assertNotIn('Test suite exceeded', result.stdout + result.stderr)

    def test_mutations_require_fresh_parallel_coverage_reports(self):
        result = subprocess.run(['make', '--no-print-directory', '-n', 'mutation', 'DC=true'],
                                cwd=ROOT, capture_output=True, text=True, check=False)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn('--coverage-xml var/coverage/coverage-xml', result.stdout)
        self.assertIn('--log-junit var/coverage/junit.xml', result.stdout)
        self.assertIn('--coverage=var/coverage --skip-initial-tests', result.stdout)
        self.assertLess(result.stdout.index('vendor/bin/paratest'), result.stdout.index('vendor/bin/infection'))
        self.assertIn('--with-timeouts --max-timeouts=0', result.stdout)
        self.assertIn('Skipped: 0', result.stdout)

    def test_test_database_is_private_ephemeral_and_opt_in(self):
        environment = dict(os.environ, REDIS_PASSWORD='test-validation-only', REDIS_URL='redis://:test-validation-only@redis:6379')
        result = subprocess.run(['docker', 'compose', '--env-file', '.env.docker.example', '--profile', 'test', 'config', '--format', 'json'], cwd=ROOT, env=environment, capture_output=True, text=True, check=True)
        services = json.loads(result.stdout)['services']
        self.assertIn('database-test', services)
        database = services['database-test']
        self.assertFalse(database.get('ports'))
        self.assertFalse(database.get('volumes'))
        self.assertIn('test', database['profiles'])
        self.assertTrue(any('/var/lib/postgresql/data' in mount for mount in database['tmpfs']))


if __name__ == '__main__':
    unittest.main()
