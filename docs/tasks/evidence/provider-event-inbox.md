# Authenticated Stripe inbox checkpoint — 2026-10-11

`ReceiveProviderEvent::receive(rawBody, signature, trustedNow)` authenticates the exact raw body before its provider-scoped inbox write. It returns true for a newly committed receipt and false for a deduplicated receipt; neither result establishes a paid purchase. The existing `provider_event_inbox` table and unique provider/scope/event identity are reused without another business table.

The installed Stripe SDK verifies the signature. Its verifier uses global system time and has no clock parameter, so the adapter separately validates a single integer timestamp and the same ±300-second tolerance against the trusted runtime clock, while using SDK signature verification for cryptography. Header structure is checked first because malformed SDK header parts otherwise produce a TypeError. Both exact tolerance boundaries pass; old, future and duplicate timestamps fail. Missing signing secret or provider scope fails at use time, keeping application construction and health checks available. Test/live mode must match configured mode.

The protected JSON payload retains the original event ID/type/API version/created time/mode/account when supplied, and original Session/PaymentIntent/charge identifiers, money, status and checkout/operation correlation facts. Customer, billing, receipt-email and unrelated private metadata are excluded. The HTTP endpoint does not return these stored facts. This is a receipt projection; signed event processing, ownership finalization, payload retention/purge and financial recovery remain following tasks.

Writes must commit independently before acknowledgement. Caller-owned transactions are refused, database failures cross the port as a sanitized RuntimeException, and redelivery never overwrites the original receipt or payload. A real PostgreSQL trigger-induced failure proved no receipt was committed and the same authenticated request succeeds after the failure is removed. Out-of-order and unrelated signed deliveries remain pending and cannot create purchases or financial operations.

Observed red → green cycles:

- Missing public receipt use case → SDK authentication and real PostgreSQL persistence.
- Stale/future signed requests incorrectly accepted → trusted-clock replay bound.
- Wrong-mode signed request accepted → test/live mode guard.
- Missing configuration returned client-authentication error → fail-closed operator RuntimeException.
- Caller-owned uncommitted write was acknowledged → independent-commit guard.
- Actual PostgreSQL persistence failure escaped as vendor exception → sanitized retryable RuntimeException and successful redelivery.
- Malformed `v1` header caused SDK TypeError → structural rejection before SDK cryptography.

```sh
docker compose --env-file .env.docker exec -T -e APP_ENV=test -e XDEBUG_MODE=coverage php vendor/bin/phpunit tests/Integration/Finance/ProviderEventInboxTest.php --coverage-clover var/provider-inbox-coverage.xml
docker compose --env-file .env.docker exec -T php vendor/bin/phpstan analyse src/Application/Finance src/Infrastructure/Finance tests/Integration/Finance --no-progress
docker compose --env-file .env.docker exec -T -e APP_ENV=test php bin/console lint:container
```

Observed focused result: 16 tests, 104 assertions, green. New code line coverage: input DTO 1/1, receipt use case 2/2, inbox adapter 6/6, Stripe authenticator 38/38. Container lint passes and formatting is applied. Signature fixtures use the official SDK with an explicit timestamp, isolated PostgreSQL databases and controlled test signing secrets; no external Stripe call or live signing secret was used. Broader HTTP, global coverage and mutation gates are tracked separately by the project coordinator. The PAY-03 task remains open until its full acceptance and quality gates are satisfied.
