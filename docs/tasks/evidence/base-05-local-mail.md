# BASE-05 — Local credential mail portion

Completed local messaging/mail checkpoint 2026-10-09. BASE-05 remains in progress: private proof storage/access/cleanup, Cloud object storage and production SMTP setup are separate unverified deliverables.

## Behavior

The outbox router is the single Messenger handler for OutboxMessage. It selects exactly one inward-owned OutboxEventHandler by event type and rejects unsupported or ambiguous registries before invoking any handler. CredentialMailMessageHandler handles only identity.credential_mail version 1, with bounded email/purpose/token/expiry validation. The expiry is the actual one-hour credential expiry provided by Identity, not reconstructed from delivery time.

Verification/reset messages use configured APP_BASE_URL and MAILER_FROM; HTTP request hosts do not construct credential URLs. The Symfony adapter uses the direct configured mailer transport, so returning from SMTP send signifies provider acceptance rather than another asynchronous enqueue. Links use the existing French verification/reset routes.

Version20261008000300 adds delivery_state JSONB object metadata to existing outbox rows, without another table or a rewrite of payload/financial intent. SMTP sending state and stable pseudonymous Message-ID commit before the network call. Acceptance is then recorded. An exception, interrupted sending attempt or unconfirmed outcome becomes unknown and blocks blind automatic resend. State accepted means SMTP accepted the message; it does not establish recipient inbox delivery or exactly-once SMTP effects.

Unknown outcomes require authorized reconciliation against the stable Message-ID before any new send decision. No automatic SMTP deduplication guarantee is claimed. The original private outbox payload still needs the category-specific redaction/retention work in PRIV-03; these local adapters do not claim RGPD certification.

## Observed TDD and verification

- Real Mailpit slice: observed missing CredentialMail class red; green actual SMTP capture with trusted verification URL and repeated-send suppression.
- Crash-state slice: observed expected unknown versus actual sending red. The first implementation threw inside the transaction and rolled back its outcome projection. Changed it to commit the unknown transition before raising the sanitized exception; green.
- Message validation slice: observed missing CredentialMailMessageHandler red, then green invalid event/version/JSON/fields/email/purpose/token/date cases with no send.
- Router slice: observed missing OutboxMessageRouter red; green 3 tests / 6 assertions for exactly one, unsupported and ambiguous routing.
- Credential suite checkpoint: 15 tests / 91 assertions green, covering real verification/reset Mailpit capture, expiry, failure/unknown outcomes, missing intent and independent delivery projection rollback/replay without deleting outbox intent.
- Configured pipeline smoke: actual `app:outbox:dispatch --limit=1` followed by `messenger:consume async --limit=1 --time-limit=5 --sleep=0.01` against an isolated mail_test_* database passed 1 test / 7 assertions. The configured handler/transport accepted the message, acknowledged queue storage, and command output contained neither synthetic token nor recipient email.
- Combined Operations checkpoint before the configured pipeline addition: 31 tests / 215 assertions, 124/125 lines (99.2%) across Operations and 26/26 (100%) in Application Operations. The sole uncovered infrastructure line rejects an unexpected stored field type already prevented by schema constraints. Scoped PHPStan level 10 and style checks passed; Deptrac reported zero violations/warnings/errors.

Tests use only local Mailpit SMTP and synthetic .test recipients. They create/drop only uniquely named isolated PostgreSQL databases. No external recipient, production service, volume reset or commit was used.

## Development migration

After status confirmed exactly Version20261008000300 pending, `make migration-dry-run` succeeded (3 SQL queries) and `make migrate` applied exactly the additive version (1 SQL query). The existing application tables were retained. Rollback/replay was tested only in isolated databases; rollback discards outcome projections and must not be used as a live SMTP recovery technique.

Composition root binds CredentialDeliveryStore to DbalCredentialDeliveryStore and CredentialMailSender to SymfonyCredentialMailSender using Symfony 8.1's mailer.default_transport service. CredentialMailMessageHandler carries the app.outbox_event_handler tag. Shared configuration and final project-wide quality are integrated by the root agent.

## Credential access-log checkpoint

A synthetic64-character verification token was observed in the original Nginx access log (red). The Nginx map now classifies the original request URI before internal front-controller rewrites and replaces verification/reset paths with a redacted label. The safe access format omits query strings, referer and user agent. Nginx configuration validation passed; after reload, a synthetic request returned400, its token/query were absent and `/verification/[redacted]` was recorded. The updated configuration is included in both successful Docker builds. This evidence covers access logs; native upstream-error logs and Cloud logging/retention still require review before BASE05/production completion.
