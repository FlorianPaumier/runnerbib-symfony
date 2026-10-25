# NEWS01 independent newsletter evidence

Implementation resumed and verified on 2026-10-11. Source remains in the Symfony application; no account/waitlist relationship or new schema/table was introduced. Newsletter retention and purge policy remain unresolved and no policy was invented.

## Public contract

- `ManageNewsletter::subscribe(requestId, email, now): void` normalizes an address, validates it, and atomically commits the unique subscription plus `newsletter.subscription_registered` welcome intent. `requestId` is a fresh server-generated UUID; it is the durable welcome intent ID. Duplicate active signup creates neither a second row nor a second message.
- `ManageNewsletter::unsubscribe(token, optionalReason, now): bool` verifies a purpose-specific signed link, requires no account or reason, and updates only its current subscription generation. Already-unsubscribed or obsolete generations return false. Invalid signatures/formats raise `InvalidArgumentException` without exposing private data.
- Rejoin preserves the existing row, updates join time, resets quit timestamp/reason, and invalidates previous unsubscribe authority. A one-microsecond monotonic join-time increment handles the same instant or a backwards clock without a new version column.
- Signed links contain subscription UUID and join instant, never an email. HMAC uses the `newsletter.unsubscribe` purpose context. Keep the configured signing secret stable; changing `APP_SECRET` invalidates previously issued links unless key continuity is separately provided.
- `DeliverNewsletterWelcome::deliver(effectKey, token, now): bool` re-reads the active generation and authoritative address before delivery, suppressing stale/unsubscribed welcomes. It reuses the existing effect-key SMTP journal and stable Message-ID. Ambiguous transport outcomes become unknown and require reconciliation; automatic replay does not send again.

HTTP integration uses `/newsletter/desinscription/{token}`. Root owns French screens, CSRF/rate limiting, no-store/no-referrer responses, Nginx token-path log redaction and the composition root. The welcome event handler is `NewsletterMessageHandler`, tagged `app.outbox_event_handler`, supporting only v1 `newsletter.subscription_registered`; payload contains only `unsubscribe_token`. `SymfonyNewsletterMailSender` sends through the configured direct mail transport, with trusted `APP_BASE_URL` and `MAILER_FROM`.

## Observed red/green runs

- Signup: missing `ManageNewsletter` class red; normalized/idempotent independent signup green 1 test / 8 assertions.
- Unsubscribe: missing public `unsubscribe` method red; no-reason/idempotent unsubscribe green 1 test / 8 assertions.
- Rejoin: existing upsert implementation verified green 1 test / 15 assertions. No separate red for this already-present upsert was claimed.
- Welcome suppression: missing `DeliverNewsletterWelcome` class red; unsubscribed welcome suppressed green 1 test / 7 assertions.
- Valid welcome: returned false red; accepted send-once green 1 test / 8 assertions.
- Ambiguity: raw private SMTP exception escaped red; sanitized unknown journal and no blind replay green in the following lifecycle suite.
- Controlled SMTP adapter: missing `SymfonyNewsletterMailSender` class red; French welcome, trusted one-click URL and Message-ID green 1 test / 9 assertions. No external SMTP was contacted.
- Registered event handler: missing `NewsletterMessageHandler` class red; purpose-bound dispatch/dedup green 1 test / 8 assertions.
- Additional real-PG tests prove welcome-intent failure rolls back signup, and two simultaneously executing normalized signups leave one row and one message. Signed wrong-purpose, wrong-secret, tampered payload/signature, malformed Base64/UUID/date and rollover dates fail closed.

## Commands and measurements

Use container PHP and explicitly select test environment:

```sh
docker compose --env-file .env.docker exec -T -e APP_ENV=test php vendor/bin/phpunit tests/Integration/Newsletter
docker compose --env-file .env.docker exec -T php vendor/bin/phpstan analyse src/Application/Newsletter src/Infrastructure/Newsletter tests/Integration/Newsletter --no-progress
docker compose --env-file .env.docker exec -T -e APP_ENV=test -e XDEBUG_MODE=coverage php vendor/bin/phpunit tests/Integration/Newsletter --coverage-clover var/newsletter-coverage.xml
```

Initial completed focused run: 29 tests / 131 assertions passed; PHPStan level 10 clean; targeted Symfony formatting applied. Xdebug measured 100% Application Newsletter lines (33/33), HMAC signer (17/17), event handler (13/13), mail adapter (5/5). Typed defensive database-corruption guards remain honestly uncovered; no source exclusions or fake corruption fixtures were introduced. Additional owning-transaction contract verification was added and final measurements follow below.

Full global quality, production image/runtime verification and complete NEWS01 acceptance remain root integration work. The task completion checkbox has not been changed.

Final focused Xdebug verification after consolidating duplicated row hydration: **30 tests / 136 assertions green**, **84/85 Newsletter source lines = 98.82%**, **Application 33/33 = 100%**. HMAC signer, registered handler and Symfony sender each remain 100%. The single remaining uncovered line is the adapter's defensive invalid-stored-row exception; it was preserved, not excluded or exercised with contrived schema corruption. Public persistence contract checks confirm mutations reject calls outside the owning transaction. Final scoped PHPStan level 10: no errors. Source is stable for root's global checks.
