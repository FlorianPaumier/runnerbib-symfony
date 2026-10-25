# BASE-03 — Durable operations primitives

Implemented 2026-10-08. Application owns transaction, outbox, audit, consumption, dispatch and claim contracts. DBAL adapters share the injected connection; the Messenger adapter uses MessageBusInterface. Message intent is a typed immutable DTO; audit rows carry actor/service authority, action, subject, reason and outcome metadata, not uploaded evidence bodies.

## Observed TDD evidence

Commands used: `docker compose --env-file .env.docker exec -T -e APP_ENV=test php vendor/bin/phpunit tests/Integration/Operations`, with `--filter` for each new slice.

- Atomic business/audit/outbox slice: observed red missing DbalTransactionManager; green 1 test / 9 assertions.
- Failed/repeated consumption slice: observed red missing DbalEffectConsumer; green 2 tests / 18 assertions.
- Retry/claim slice: observed red missing MessageDispatcher and DbalOutboxQueue; green 4 tests / 39 assertions.
- Real Messenger boundary: observed red missing MessengerMessageDispatcher; subsequent green 6 tests / 60 assertions, including a second-connection locked-row skip.
- Owning transaction enforcement: observed red `An independent write must not commit`; green 8 tests / 79 assertions after guards.
- Additional recovery/concurrency verification: scoped coverage run passed 9 tests / 90 assertions before strengthening retry projection assertions. Tests use random operations_test_* databases, existing Doctrine migration commands and real PostgreSQL; only those fixtures are dropped.

The suite observes rollback of all three writes, committed state from a second connection, failed consumer rollback, redelivery suppression, concurrent duplicate consumption across a child process, SKIP LOCKED, claim generation fencing, retry due time, expired lease recovery and real MessageBus delivery. Acceptance followed by lost acknowledgement leaves outbox work retryable; repeated delivery commits only one consumer database effect. Dispatch is asserted outside the database transaction.

## Quality and coverage

Scoped PHPStan level 10 passed without ignores or baselines. Scoped PHP CS Fixer ran; Deptrac reported zero violations/warnings/errors. Operations scoped coverage was 58/59 executable lines (98.31%); Application Operations was 12/12 (100%). The uncovered adapter line rejects an unexpected PostgreSQL field type, which the installed schema prevents in normal storage. This is not an assertion of 100% infrastructure coverage.

A full `make coverage` run reached 109 tests / 374 assertions but failed on another agent's race slice while FindPublicRace was not yet present. That run is not reported as a successful full quality gate. Root integration must rerun the complete suite after concurrent slices finish.

## Composition and usage

Bind Application Operations ports to their corresponding Infrastructure Operations implementations: TransactionManager → DbalTransactionManager; OutboxWriter → DbalOutboxWriter; AuditWriter → DbalAuditWriter; EffectConsumer → DbalEffectConsumer; OutboxQueue → DbalOutboxQueue; MessageDispatcher → MessengerMessageDispatcher. Exclude OutboxMessage, AuditEntry and ClaimedOutboxMessage DTOs from service discovery. Inject the same DBAL connection into participating adapters.

TransactionManager::run accepts a void Closure; DBAL nested transactions use savepoints. OutboxWriter/AuditWriter require an owning business transaction. EffectConsumer::consume commits deduplication and the callback's database effect together. Callbacks must perform database work, not provider network calls. Consumer identities are stable per handler; keys persist long enough to protect redelivery.

DispatchOutbox::dispatchNext receives the current instant and worker identity. Claims lease for 60 seconds, increments persisted fencing generation and commits before transport dispatch. Errors release the matching generation with next_attempt_at 60 seconds later; stale generations cannot acknowledge or change retry projections. Application consumers must deduplicate at their database boundary. This provides at-least-once delivery plus once-per-consumer database effects, not exactly-once external email/provider effects.

Durable local Messenger transport/configuration, worker commands and Cloud deployment are subsequent BASE-04 integration. Business handlers and financial provider reconciliation belong to their feature tasks. No commit or production deployment was performed.
