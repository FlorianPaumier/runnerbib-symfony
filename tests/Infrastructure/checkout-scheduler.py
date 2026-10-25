"""Exercise the scheduler process at its PHP command boundary."""

import os
from pathlib import Path
import signal
import subprocess
import tempfile
import time
import unittest


ROOT = Path(__file__).resolve().parents[2]


class CheckoutSchedulerTest(unittest.TestCase):
    def test_payment_outage_does_not_stop_other_jobs_and_shutdown_is_clean(self):
        with tempfile.TemporaryDirectory(prefix="runnerbib-scheduler-") as directory:
            folder = Path(directory)
            log = folder / "calls.log"
            php = folder / "php"
            php.write_text(
                '#!/bin/sh\nprintf "%s\\n" "$*" >> "$SCHEDULER_TEST_LOG"\n'
                'case "$*" in *app:checkout:reconcile*) exit 1;; esac\n'
            )
            sleep = folder / "sleep"
            sleep.write_text(
                '#!/bin/sh\nprintf "sleep\\n" >> "$SCHEDULER_TEST_LOG"\n'
                'if [ "$(wc -l < "$SCHEDULER_TEST_LOG")" -ge 14 ]; then exec /bin/sleep 30; fi\n'
            )
            php.chmod(0o700)
            sleep.chmod(0o700)
            environment = dict(os.environ, PATH=directory + os.pathsep + os.environ["PATH"], SCHEDULER_TEST_LOG=str(log))
            process = subprocess.Popen(["sh", str(ROOT / "docker/php/scheduler.sh")], cwd=ROOT, env=environment, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)
            try:
                deadline = time.monotonic() + 5
                while time.monotonic() < deadline and process.poll() is None:
                    if log.exists() and len(log.read_text().splitlines()) >= 14:
                        break
                    time.sleep(0.01)
                time.sleep(0.05)
                process.terminate()
                self.assertEqual(0, process.wait(timeout=5))
                self.assertEqual([
                    "bin/console app:availability:run --limit=100",
                    "bin/console app:outbox:dispatch --limit=100",
                    "bin/console app:checkout:reconcile --limit=100",
                    "bin/console app:provider-events:process --limit=100",
                    "bin/console app:sellers:reconcile --limit=50",
                    "bin/console app:retention:purge",
                    "sleep",
                ] * 2, log.read_text().splitlines())
            finally:
                try:
                    os.killpg(process.pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
                process.wait(timeout=5)


if __name__ == "__main__":
    unittest.main()
