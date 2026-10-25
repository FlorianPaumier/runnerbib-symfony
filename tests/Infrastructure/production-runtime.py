"""Warm the built production image using only its declared Compose environment."""

import json
import os
from pathlib import Path
import re
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]


class ProductionRuntimeTest(unittest.TestCase):
    def test_built_image_warms_without_a_development_environment_file(self):
        variables = set(re.findall(r"\$\{([A-Z_0-9]+)", (ROOT / 'compose.prod.yaml').read_text()))
        environment = {key: value for key, value in os.environ.items() if key not in variables}
        environment.update({
            'APP_SECRET': 'runtime-validation-only',
            'NEWSLETTER_SIGNING_SECRET': 'newsletter-runtime-validation-only',
            'POSTGRES_PASSWORD': 'bootstrap-validation-only',
            'RUNTIME_DATABASE_URL': 'postgresql://runtime_validation:validation@database:5432/app?serverVersion=17',
            'REDIS_PASSWORD': 'redis-validation-only',
            'REDIS_URL': 'redis://:redis-validation-only@redis:6379',
            'MAILER_DSN': 'null://null',
            'MAILER_FROM': 'no-reply@example.test',
            'APP_BASE_URL': 'https://example.test',
        })
        config = subprocess.run(
            ['docker', 'compose', '--env-file', '/dev/null', '-f', 'compose.prod.yaml', 'config', '--format', 'json'],
            cwd=ROOT, env=environment, capture_output=True, text=True, timeout=30, check=True,
        )
        runtime = json.loads(config.stdout)['services']['php']['environment']
        platform = subprocess.check_output(['docker', 'version', '--format', '{{.Server.Os}}/{{.Server.Arch}}'], text=True).strip()
        command = ['docker', 'run', '--rm', '--platform', platform, '--entrypoint', 'sh']
        for key, value in runtime.items():
            command.extend(['-e', f'{key}={value}'])
        command.extend(['runnerbib-symfony-prod-php', '-c',
                        'php bin/console cache:clear --env=prod --no-debug && php bin/console lint:container --env=prod --no-debug'])
        result = subprocess.run(command, cwd=ROOT, capture_output=True, text=True, timeout=60, check=False)
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)


if __name__ == '__main__':
    unittest.main()
