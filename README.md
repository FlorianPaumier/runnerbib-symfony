# Runnerbib Symfony application

Symfony 8.1 on PHP 8.4 (minimum 8.4.1), with Nginx, PostgreSQL 17, Redis 7.4 and Mailpit. Agent rules are in `AGENTS.md` and the workspace `../AGENTS.md`; role instructions are in `.agents/roles/`.

## Local development

Requirements: Docker Engine/Desktop with Docker Compose and Make. Host PHP and Composer are optional.

```sh
cp .env.docker.example .env.docker
make up
make test
make coverage
make lint
```

`make test` and `make coverage` run the complete suite with ParaTest, one
process per Docker CPU, distributing individual tests and data sets between
workers. CLI OPcache avoids repeatedly compiling PHP files. The regular test command
disables Xdebug; `make coverage` enables it explicitly. Override the width
with `make test TEST_PROCESSES=4`; use `make test-serial` while debugging.
Each integration test still gets an independent, migrated PostgreSQL database.
Tests use the opt-in `database-test` Compose service, with its data directory on
a 1 GiB tmpfs (memory-backed storage), no host port and no persistent volume.
The test command starts it and includes readiness time in its budget. PostgreSQL
keeps its normal transaction/locking and synchronous durability settings; WAL
checkpoint sizes are bounded for this disposable filesystem. Stopping this
service discards only test data. Development database volumes are untouched.
`TEST_DATABASE_URL` can explicitly select another test server.

`make test` measures the complete command and fails if it takes **120 seconds
or longer**, after allowing every test to finish and clean up, including test-database readiness time. It preserves test
failures. `TEST_MAX_SECONDS` overrides this budget for explicit diagnostics;
CI uses the default. Coverage, mutation analysis, image builds and stack startup
have separate costs and are outside this regular-suite budget. Runtime depends
on available CPUs, disk speed and competing work; the gate detects a missed
budget rather than guaranteeing speed on every machine.

Development Make commands build for the Docker server's native architecture,
even if the shell globally sets `DOCKER_DEFAULT_PLATFORM`. To intentionally
emulate another architecture, pass `DOCKER_PLATFORM=linux/amd64` (or
`linux/arm64`). After switching architecture, run `make build`, then
`DOCKER_DEFAULT_PLATFORM=$(docker version --format '{{.Server.Os}}/{{.Server.Arch}}') docker compose --env-file .env.docker up -d --no-deps --wait php`
to recreate only PHP without changing database volumes. Production builds keep
their separately selected target architecture.

Run `python3 tests/Infrastructure/test-runner.py` to verify that the Make target
accepts a passing run, rejects a missed budget and preserves test failures.

If port 8080 is occupied, change `HTTP_PORT` in `.env.docker` before starting. This workspace currently uses port 8082.

Open http://localhost:8080/health for liveness and http://localhost:8080/ready for database readiness. `/ready` returns HTTP 503 when PostgreSQL is unavailable. French home, race search/detail, account, organization, waitlist and notification pages are available. Mailpit is at http://localhost:8025; SMTP is `mailpit:1025` within Docker. PostgreSQL and PHP-FPM have no host ports.

`make up` builds images, installs locked Composer dependencies into a named volume and waits for services. Source is bind-mounted; vendor and Symfony runtime files use named volumes. After changing composer.lock, run `make install`. Use `make down` to stop services while preserving data. `make reset` removes **all development volumes, including the database**.

```sh
docker compose exec php bin/console about
docker compose exec php composer require some/package
make logs
make shell
```

Make uses `.env.docker` automatically when present. For direct Compose commands, pass `--env-file .env.docker` when using customized settings. Database credentials in the example are for local development. Passwords included in DATABASE_URL must be URI encoded; use URL-safe secrets with this Compose template.

Enable debugging with `XDEBUG_MODE=debug` in `.env.docker`, recreate PHP, listen on port 9003 in your IDE and map `/app` to this directory. Xdebug starts on a request trigger. Set `XDEBUG_MODE=coverage` for coverage runs. Xdebug is absent from production.

## Architecture and TDD

Saved decisions and plans are indexed in [architecture plans](docs/architecture/README.md), including the [quality plan](docs/architecture/quality-plan.md) and [PostgreSQL schema plan](docs/architecture/database-schema-plan.md).

The proposed [domain model architecture plan](docs/architecture/domain-model-plan.md) defines module ownership, aggregates, transaction boundaries, persistence safeguards and the TDD implementation sequence.

Dependencies point inward: Presentation invokes Application, Application owns ports and depends on Domain, and Infrastructure implements those ports. Symfony configuration wires adapters. Domain implements race, identity, organization, inventory and financial rules; vocabulary comes from `../specifications/glossary.md`.

Write one behavioral test, observe red, implement the smallest green change, then refactor while tests stay green. Initial agreed seams are the HTTP health endpoints and real PostgreSQL connectivity. Persistence and HTTP tests create uniquely named temporary PostgreSQL databases and remove only their own fixtures. Run `make lint` for container/YAML validation and Deptrac architecture checks.

Coverage targets 100%. `make coverage` enables Xdebug for the test process, includes uncovered source files and enforces **98% minimum line coverage overall**, with **100% in Domain/Application**. HTML reports are in the runtime volume at `/app/var/coverage/html`. To view locally, run `docker compose cp php:/app/var/coverage ./coverage` (add your environment-file option if needed). CI uploads the reports. Cover business-rule branches and failure/recovery scenarios as well as lines; the initial gate measures line coverage.

## Quality tools

Run `make quality` before completing a change. Individual commands:

| Command | Check |
|---|---|
| `make test` | Complete parallel suite; fails at 120 seconds or longer |
| `make test-serial` | Full suite in a single PHPUnit process |
| `make coverage` | PHPUnit/Xdebug in parallel; >=98% overall lines, 100% Domain/Application |
| `make lint` | Symfony configuration/services and Deptrac dependency direction |
| `make analyse` | PHPStan level 10 over src/, tests/ and tools/, with Symfony/PHPUnit extensions; no baseline |
| `make cs-check` / `make cs-fix` | Check/apply Symfony PHP formatting |
| `make mutation` | Infection over Domain/Application; >=90% MSI and >=95% covered MSI |
| `make audit` | Composer dependency vulnerabilities |

PHPStan level 10 includes lower-level checks and rejects unsafe operations on explicit or inferred `mixed`. Narrow unknown data with real validation before using it. It analyzes code without executing it; business scenarios still need tests.

Infection changes production code temporarily and checks that tests detect those changes. Business-rule mutation checks are required. A zero-candidate result is not an achieved mutation score. Final global mutation evidence remains a completion gate. `make mutation` first generates fresh parallel coverage/XML/JUnit reports, then Infection consumes them without repeating the full initial suite in series; `make quality` shares that coverage dependency. Infection runs the test cases that actually cover each mutated line, while keeping all configured mutators and score thresholds. Its per-mutant limit is 300 seconds with four workers so broad integration coverage is exercised. Any timed-out or time-skipped candidate fails the mutation gate. Mutation subprocesses run with Xdebug disabled once the fresh coverage reports exist. Uncovered core code is included in mutation analysis. CI uploads coverage and mutation HTML reports.

In the test environment, Symfony writes application logs to `/app/var/test.log` in the runtime volume. Expected HTTP rejection logs must not go to stderr: Infection stops its initial test run on stderr output. PHP errors and PHPUnit failures remain visible.

## Production

`compose.prod.yaml` is standalone. It contains immutable application and web images, locked dependencies without development packages, no source mounts, no Mailpit, persistent PostgreSQL storage and a PHP runtime volume. PHP runs as www-data with debug disabled and warms its cache at startup. Migrations are a separate deployment step; startup never changes schema.

Create a deployment-specific environment file outside version control, with a strong APP_SECRET, bootstrap POSTGRES_PASSWORD, a separately provisioned restricted-role RUNTIME_DATABASE_URL, a real MAILER_DSN, REDIS_PASSWORD/REDIS_URL, trusted APP_BASE_URL and verified MAILER_FROM. `APP_BASE_URL` is also the router origin for CLI URL generation; no separate `DEFAULT_URI` is needed. Production Compose refuses to reuse bootstrap credentials when RUNTIME_DATABASE_URL is missing. Development keeps owner access for isolated database/migration tests.

Provision migration/schema-owner and runtime roles through deployment secret management. As the owner, apply migrations explicitly and run `tools/database-runtime-grants.sql` with `psql -X -v ON_ERROR_STOP=1 -v runtime_role=<restricted-role> -f tools/database-runtime-grants.sql`. The script rejects elevated/owner roles and grants only the reviewed runtime operations. Reapply the reviewed grants after forward migrations. The PHP runtime must not execute migrations with its own credentials; use a dedicated migration job with its owner-only `DATABASE_URL`. Do not put owner credentials into the running PHP container.

After building the standalone production images, `python3 tests/Infrastructure/production-runtime.py` verifies cache warmup and container wiring with only the declared Compose environment. It runs a disposable image with validation credentials and does not start the production stack.

Run `python3 tests/Infrastructure/production-compose.py` on the Docker host to verify fail-closed credential separation and private database/Redis port configuration without starting services. Compose configuration alone does not prove a supplied role has safe PostgreSQL privileges; execute the role checks and restricted-role runtime smoke before release. Then:

```sh
docker compose --env-file /path/to/production.env -f compose.prod.yaml config --quiet
docker compose --env-file /path/to/production.env -f compose.prod.yaml build --pull
docker compose --env-file /path/to/production.env -f compose.prod.yaml up -d --wait
```

The production web port defaults to loopback port 8081. Put a TLS reverse proxy in front of it and configure trusted proxies before deployment. Back up PostgreSQL with your deployment tooling. Service health checks cover FPM liveness and HTTP liveness; deployment readiness should additionally request `/ready`. Supply secrets through your deployment environment. Rebuild both PHP and web images when assets change. Pin image digests in a deployment pipeline for reproducible releases.

Official framework setup: https://symfony.com/doc/8.1/setup.html

## Marketplace migrations

The original consolidated 40-table schema is available through Doctrine Migrations. Run `make migration-status` or `make migration-dry-run` to inspect it; `make migrate` applies it to the configured database. Migration tests create and remove only isolated temporary databases. No application database is changed by running the test suite.

See [migration implementation](docs/architecture/migration-implementation.md) for constraints, explicit production migration execution, runtime role grants and rollback behavior. Forward migrations add queue, delivery state, preferences and a seller onboarding journal; implementation and remaining gates are recorded in the task backlog.

## Remaining project tasks

See the [project completion backlog](docs/tasks/README.md) for the delivery order, task dependencies, acceptance criteria, open decisions and release gates.

## Web assets

The shared design is documented in [DESIGN.md](DESIGN.md). Application-page styles
live in `frontend/application.css`; landing styles remain in `frontend/landing.css`.
Authentication shows the form first on mobile while retaining its desktop split layout.
Rebuild CSS after edits with `bun run assets:build` or `make assets`.

Twig, Symfony UX (Stimulus/Turbo), AssetMapper and Tailwind are installed. `make assets` builds CSS through a locked Bun container; `make assets-watch` runs the watcher with local Bun. `make up` builds development assets automatically. Production images compile and serve versioned assets through Nginx. French public race search/detail and authenticated account, organization, waitlist and inbox screens use Application facades. Remaining purchasing and finance screens are tracked in the backlog.

## Local workers and disposable Redis

Run `make outbox` to enqueue up to 100 durable intents, `make worker` to consume the local Doctrine-backed queue, and `make failed-messages` to inspect failed deliveries. These commands do not install a scheduler or launch a Cloud worker. Credential and availability emails use Mailpit locally; configure the trusted origin before generating links. Availability publication queues a batch intent. After dispatching and consuming it, run `make availability` to process due five-minute batches, then dispatch and consume the generated digest intents. Each offer keeps its original priority deadline. Unknown SMTP outcomes require reconciliation of the stable Message-ID before retrying; rerunning a worker does not blindly send again. Production scheduling remains a release task.

Redis supports disposable cache and sessions. PostgreSQL remains authoritative for ownership, availability, checkout protection and financial intent. Losing Redis can expire sessions/cache, but must not change those records. Keep Redis credentials private; do not expose its port.

Private proof uploads use `LocalProofQuarantine` through the Application storage port. `PROOF_STORAGE_DIRECTORY` defaults to `/app/var/private/proofs` in the existing persistent runtime volume, outside `public/`. Set `PROOF_ALLOWED_MIME_TYPES` to an accepted JSON list and `PROOF_MAXIMUM_BYTES` to the accepted positive byte limit; the defaults `[]` and `0` refuse uploads and downloads until configured. Files remain quarantined with private permissions; storing or downloading them does not establish malware scanning or ownership approval. Authorized reviewers can download through `/admin/preuves/{proofId}/document`; responses force an attachment and disallow caching/content sniffing. A process crash between file creation and database commit can leave an orphan requiring reconciliation. Retention cleanup and scanning remain backlog work.

`OPTIONAL_NOTIFICATION_EMAIL_POLICY_VERSION` and `OPTIONAL_NOTIFICATION_IN_APP_POLICY_VERSION` record the accepted account preference policy. `RACE_NOTIFICATION_EMAIL_POLICY_VERSION` and `RACE_NOTIFICATION_IN_APP_POLICY_VERSION` independently govern race alert grants. Defaults are empty: new grants are refused, while withdrawals and race membership without alerts remain available. These versions do not establish approved legal consent by themselves.

`NEWSLETTER_POLICY_VERSION` independently identifies the accepted newsletter signup policy. An empty value uniformly refuses signup without disclosing existing subscriptions. Signed one-click unsubscribe remains available; signup/rejoin/withdrawal evidence contains the subscription generation and policy version, without copying email or optional quit reason to audit.

Configure `STRIPE_WEBHOOK_SECRET`, `STRIPE_PROVIDER_SCOPE` and `STRIPE_LIVE_MODE` for the intended provider environment before pointing Stripe at `POST /webhooks/stripe`. The local mode defaults to test (`0`). Authentication and durable deduplication precede acknowledgment; unconfigured or unavailable persistence returns503 for retry. Authenticated events remain pending and do not themselves complete purchases.

Hosted payment submission and checkout reconciliation reuse the original durable operation and provider session. Set the Stripe provider configuration before using them; an unconfigured provider refuses work. An unknown or processing result keeps checkout protection. A confirmed unpaid terminal result can release the whole checkout; a paid result remains protected until backend purchase finalization.

Buyers start a payment from the cart with `POST /panier/payer`. The cart page renders the race-required registration fields and one unchecked transfer acknowledgment per nonparticipating race; the backend validates the answers and refuses a payment without the acknowledgment. Authorization commits the whole cart, the protection and the fee policy, then the original payment operation is submitted to the hosted provider. A provider session URL sends the buyer to Stripe; without one the buyer is sent to the authoritative state page instead. The committed provider URLs point to `/achat/retour?session={CHECKOUT_SESSION_ID}`, so Stripe substitutes the session identifier and the buyer lands on the checkout that belongs to them. Callbacks must be secure URLs: a non-https `APP_BASE_URL` is refused when a payment starts. An unconfigured provider, an unavailable seller payout account or a rejected cart answers with a French error and no protected checkout; the cart stays intact. A browser return is never purchase finalization.

Authenticated buyers can read their backend checkout status at `/achat/{checkoutId}/retour` and refresh it without submitting another payment. This private, non-cacheable page ignores success/cancellation query flags, returns the same French 404 for missing and other buyers’ checkouts, and distinguishes all stored checkout states. It requires no additional environment variables. The provider return route `/achat/retour` resolves the same state from the provider session reference and only for the buyer who owns the checkout. Recovery confirmation and whole-purchase finalization remain separate unfinished tasks.

Run `make reconcile-checkouts` (or `php bin/console app:checkout:reconcile --limit=100`) to reconcile a bounded batch of due checkouts in the configured `STRIPE_PROVIDER_SCOPE`, using the existing Stripe credentials. Limits range from 1 to 1000. The production scheduler runs this after availability and outbox work, with a 60-second sleep between cycles. No new environment variables are needed. A payment-job failure is reported without stopping the other scheduled jobs.

The batch skips live checkout leases and checks original Sessions at the initial deadline or after a cancellation request. It expires eligible open Sessions and releases all locks only after safe closure; processing, paid and unknown results keep protection. Completed inspections are spaced by at least 60 seconds for the same cancellation request, ordered ahead of recently checked work so unresolved payments cannot monopolize a bounded batch. A new deadline or withdrawal is not delayed by an earlier status check. This is reconciliation only: it does not create a replacement payment, confirm a purchase, refund funds or pay sellers.

Organizer imports are available at `/organisations/courses/{raceId}/import` to verified members of approved organizations. Set `IMPORT_HEADERS_JSON` to the approved field-to-header mapping (`bib`, `email`, optional `name`), `IMPORT_MAXIMUM_BYTES` and `IMPORT_MAXIMUM_ROWS` to approved positive limits, and `IMPORT_MUTABLE_FIELDS_JSON` to the approved update allowlist. Defaults `{}`, `0`, `0` and `[]` keep imports unavailable and permit no update fields. The preview is informational: execution requires a fresh upload and reparses it; retries reuse the import UUID. Raw CSV and holder details are not copied to session, flash or page output.

Production Compose supervises a Messenger `worker` and a `scheduler` with `restart: unless-stopped`, 256MiB container limits and a 30-second shutdown grace period. The worker consumes `async`, recycles after one hour or128MiB, and exits after10 failures; Symfony routes exhausted retries to the durable `failed` transport. The scheduler runs `app:availability:run --limit=100` and `app:outbox:dispatch --limit=100`, then waits60 seconds. A command failure exits for supervisor restart; committed intents and leases remain in PostgreSQL. Both processes use the restricted runtime credentials. Their inherited FPM health check is disabled because they do not serve FPM; monitor queue age, failed-message counts and scheduler logs instead of treating container uptime as processing evidence. Use `docker compose -f compose.prod.yaml exec worker php bin/console messenger:failed:show --stats` to inspect failures and `logs worker scheduler` for local diagnostics. Cloud supervision, alerting and deployment acceptance remain separate release tasks.

Newsletter links use a dedicated `NEWSLETTER_SIGNING_SECRET`, required explicitly by production Compose. Rotating `APP_SECRET` no longer changes unsubscribe authority. For newsletter-key rotation, set the new current key and retain explicitly accepted earlier keys in the secret JSON list `NEWSLETTER_PREVIOUS_SIGNING_SECRETS_JSON`; old links remain generation-bound and new links use only the current key. If migrating a previously running build, include its old newsletter key (formerly its APP_SECRET) in that list before rollout. Removing a previous key invalidates its mailed links, so do not retire it without an accepted transition/alternative unsubscribe process. No automatic retention period or key retirement is invented. Never commit actual keys.

Privacy request intake uses `PRIVACY_PROGRESS_LIFETIME_SECONDS`, an explicitly accepted positive lifetime in seconds for status-only authorities. Blank configuration refuses authenticated intake before mutation. Deletion intake suspends the account, revokes credentials, withdraws unsold offers and requests whole-checkout cancellation; it does not purge personal data or settle protected payments. Requests remain blocked pending retention policy and, where applicable, financial resolution. Authenticated French intake is available at `/compte/confidentialite`. The public `/confidentialite/suivi` form accepts the signed status code only in a POST body; save the displayed code to follow a request after deletion revokes the session. Both pages use private/no-store responses and the status page uses `Referrer-Policy: no-referrer`. Intake remains unavailable until an agreed lifetime is configured; local test lifetimes do not establish a production policy.

Seller listing controls at `/compte/ventes` create drafts from owned entitlements, attach current approved proofs, publish, edit prices and withdraw listings. Prices are parsed into integer cents and each action checks CSRF, ownership and listing version through the existing use cases. A protected checkout fences the entitlement across replacement listings. Withdrawal requests whole-checkout cancellation and does not clear payment protection.

## Local demonstration fixtures

Run migrations, then `FIXTURE_PASSWORD='choose-a-local-password' make fixtures`.
The password must contain at least 12 characters. The loader accepts only dev/test,
inserts in one transaction, never truncates data, and retains existing fixture
records and passwords on repeat runs. Use a disposable development database for
fresh dates/states; reloading intentionally does not undo actions taken in the app.
No additional environment variables are required for discovery and login.

| Email | Scenario |
| --- | --- |
| `admin@example.test` | Moderation administrator |
| `superadmin@example.test` | Super-admin with financial permissions |
| `organizer@example.test` | Owner of the approved demo organization |
| `manager@example.test` | Manager in the same organization |
| `buyer@example.test` | Verified runner, priority recipient, populated cart |
| `seller@example.test`, `seller2@example.test` | Verified runners with offers |
| `user@example.test` | Runner owning a pending organization |
| `unverified@example.test` | Account awaiting email verification |
| `suspended@example.test` | Suspended account |

All new accounts use the supplied local password. The set contains two organizations,
eight races (one draft), eight published offers (one initially in its shared one-hour
priority window), one draft and one withdrawn offer, two waitlist memberships,
and two moderation examples. Dates are relative to the first load. Public offer
prices range from €24 to €95 before the existing buyer fee.

Synthetic document proofs are stored outside `public/`; they are explicitly marked
as invalid demonstration documents. To enable downloads/uploads, configure
`PROOF_MAXIMUM_BYTES=1048576` and `PROOF_ALLOWED_MIME_TYPES=["application/pdf"]`
in `.env.docker`, then recreate the PHP service. Existing storage policy stays intact.
No Stripe accounts, provider payments, real purchases, refunds or payouts are fabricated.
The administrator account-state route is `/admin/comptes/{accountId}/statut`;
there is no `/admin` index in the current application.

Checks: `docker compose --env-file .env.docker exec -e APP_ENV=test php vendor/bin/phpunit tests/Integration/Console/DevelopmentFixturesTest.php`.
The integration test checks repeated loading, account/offer counts, actual login,
admin, organizer, manager, seller and buyer access, valid registration schemas,
and a private synthetic proof download in an isolated PostgreSQL database.
