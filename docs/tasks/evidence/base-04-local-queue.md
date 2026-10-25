# BASE-04 — Local durable queue portion

Implemented and exercised 2026-10-08. BASE-04 remains in progress: managed Cloud queues, Redis/cache/session policy and actual Cloud resource configuration are not verified by this local work.

## Migration and transport

Version20261008000200 adds only technical `messenger_messages` storage and its queue/due/delivery index. The applied 40-table version is unchanged. The marketplace-count test excludes this technical table without changing its expected 40 marketplace tables. Both async and failed queues use this table with distinct queue_name values, auto_setup=false and use_notify=false. Runtime DDL is unnecessary; the grant script conditionally grants queue row operations and sequence usage after the queue migration exists.

Observed red: MessengerTransportTest::testQueueMigrationCanBeAppliedRolledBackAndReplayedIndependently failed because to_regclass returned null rather than messenger_messages. After implementation, independent queue apply/rollback/replay and transport persistence tests passed 2 tests / 14 assertions. Rollback to the first marketplace version retained races and removed only queue storage; replay restored queue storage. Rollbacks ran only in queue_test_* temporary databases.

Development status showed one pending additive version. `make migration-dry-run` succeeded (4 SQL queries); `make migrate` applied exactly that version (2 SQL queries). No application tables were reset or dropped.

## Worker evidence

Final MessengerTransportTest passed **4 tests / 23 assertions** through real PostgreSQL and actual `bin/console messenger:consume` subprocesses. An unhandled synthetic stdClass message exhausted the configured three retries and entered durable failed storage. An idle worker exited successfully at its time limit. Transport recreation retained an enqueued message; failed/async queues remained separate and acknowledgement removed only the source queue record.

Use `--sleep=0.01` for bounded local tests: Symfony 8.1's CLI sleep option is in seconds, not microseconds. An earlier smoke used 10000 and stopped at its time limit before retries became due; that was corrected after inspecting command help. No successful failure-queue claim is made for that earlier run.

After the root agent built a pcntl-enabled development image, `/tmp/runnerbib-worker-signal.py` started a separate `docker compose run --rm --no-deps` worker against a new queue_smoke_* database. It sent SIGTERM to that dedicated container. Observed logs: `Received signal 15`, `Stopping worker`, process exit 0. Fixture database/container were removed. Existing PHP containers and sibling tests were not interrupted.

Scoped PHPStan level 10 passed for Operations and the new queue migration. Full-suite quality/production images are integrated by the root agent. No Cloud deployment or commit was performed.

## Operations

Run `php bin/console messenger:consume async --time-limit=3600 --memory-limit=128M` under a supervisor that restarts successful bounded exits. Send SIGTERM for graceful shutdown with pcntl enabled. Inspect failures with `messenger:failed:show`; reconcile effects before `messenger:failed:retry`. Do not blindly replay uncertain financial/provider operations. This local generic retry policy does not replace the next-day seller-transfer policy.

The outbox publisher and consumer handlers are separate responsibilities: publishing durably enqueues an intent, then consumers enforce their own effect deduplication. PostgreSQL queue redelivery timeout is 3600 seconds; long handlers must use supported keepalive or an appropriately reviewed timeout. Avoid putting slow provider calls inside a database transaction. Apply migrations before starting workers; rollback destroys queued work and is restricted to isolated tests here.
