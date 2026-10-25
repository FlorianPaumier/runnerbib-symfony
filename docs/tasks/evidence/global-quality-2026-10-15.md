# Global quality continuation — 2026-10-15

Resumed from the 2026-10-13 coverage checkpoint. Legacy applications were not changed. The preserved development Compose stack was started with `docker compose --env-file .env.docker up -d --wait`; all five services became healthy, without deleting volumes.

## Mutation baseline logging blocker

The first `make quality` passed 763 tests / 4,510 assertions, overall coverage 98.03% (2,741/2,796) and Domain/Application 100% (943/943), configuration/architecture checks, PHPStan 10 and formatting. Infection then terminated its initial randomized PHPUnit run with exit 143, before generating mutations. Composer audit was run separately and found no vulnerability advisories.

Root cause: Infection 0.35.6's `InitialTestsRunner` calls `Process::stop()` on any stderr output. Symfony's fallback logger writes expected HTTP rejection exceptions to stderr. This was not an assertion failure or an out-of-memory container restart. A separate diagnostic baseline replay was deliberately stopped after locating this cause in the installed runner.

`TestLoggingTest::testExpectedHttpErrorsDoNotWriteToStderr` observed red: the request returned 404 but emitted Symfony logs on stderr. Test-only service wiring now directs those logs to `/app/var/test.log`, preserving diagnostics and production logging. The first path under `var/log` failed because that directory did not exist; the corrected path uses the existing runtime directory. That intermediate configuration and its failed full run are not green evidence.

The next full run exposed the worker test's dependency on console log wording. Its durable queue assertions passed, but the `failure transport` console substring disappeared as expected. The test now verifies retrieval of the original message from the real failed transport. Focused regression: 5 tests / 28 assertions, exit 0.

## Stable corrected snapshot

`make quality` passed its PHPUnit/coverage stage: **764 tests / 4,551 assertions**, overall **98.03% (2,741/2,796)** and Domain/Application **100% (943/943)**. YAML/container validation, Deptrac, PHPStan 10 and formatting also passed. Global mutation results are recorded below when the command finishes.

No coverage/mutation thresholds or source exclusions were changed. No new environment variables, dependencies, commits, deployment or database reset were introduced. Runtime/provider, policy, restore and unfinished feature acceptance remain separate launch requirements.
