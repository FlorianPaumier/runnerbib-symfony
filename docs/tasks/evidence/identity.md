# Identity implementation evidence

Implemented on 2026-10-08. This is partial execution evidence, not completion of every AUTH task.

## Observed red → green

- `docker compose --env-file .env.docker exec -e APP_ENV=test php vendor/bin/phpunit tests/Identity/AccessPolicyTest.php`: red missing Actor; green 1 test, 6 assertions for account resource scope and ordinary-admin financial denial.
- `... phpunit tests/Identity/IdentityPersistenceTest.php`: red missing DbalIdentityStore; green real isolated PostgreSQL registration, normalized duplicate email, single-use/purpose/expiry credentials, verified state and password/version updates.
- `... phpunit tests/Identity/SignupPolicyTest.php`: red missing Signup; green valid signup without name and invalid email/password/terms.
- `... phpunit tests/Identity/SecurityUserTest.php`: red missing SecurityUser; green session equality checks account identity, version, password and roles.
- Application registration/recovery: red missing RegisterAccount after defining port seam; green synthetic captured delivery and real PostgreSQL persistence. Initial missing-port PHPUnit bootstrap failure was setup failure, not behavioral red.
- Atomic registration: red delivery failure left account persisted; green TransactionManager wraps account + token + mail intent and rollback removes all three.
- Runtime effects: red missing OutboxIdentityEffects; green password hashing, cryptographic UUID/token and durable mail intent in a real transaction. PostgreSQL JSONB key ordering required semantic JSON comparison.
- HTTP forms: red /inscription 404; green French signup/login/recovery/reset forms and invalid CSRF rejection.
- HTTP signup → verification → login → password reset → session revocation: initially failed test hasher time_cost 2 (sodium requires ≥3), corrected configuration, then failed missing protected /compte route. Added protected account/logout screen. Flow green.
- Rate-limiter cache persists across test runs: explicit reset of the test-only localhost limiter isolates scenarios without disabling production limits.

Latest focused `... phpunit tests/Identity`: 16 tests, 146 assertions before removal of three redundant type assertions. Full project validation is coordinated by root agent.

Focused PHPStan level 10 passed before the final extra HTTP scenarios; rerun is required after formatting. Deptrac passed with zero violations. Focused coverage observed 100% of Identity Domain/Application lines; some defensive infrastructure branches and dummy logout controller remain uncovered. No claim of global 98% coverage or mutation score.

## Implemented boundaries

- Application facade normalizes and validates signup through framework-free Domain; Presentation depends only on Application.
- Active account provider reads DBAL, refresh rejects suspended/anonymized accounts; session equality invalidates password/version/role changes.
- Credential secrets stored as SHA-256 hashes; raw mail token exists only in restricted durable outbox payload. Email is queued, not actually delivered by this slice. The operational mail dispatcher must implement rendering, sending, retries and sensitive-payload purge; never log credential payloads.
- Password hashing uses configured Symfony Argon2id factory, cryptographic 256-bit token entropy, one-hour credential expiry. Signup/reset request and login limits are five per 15 minutes.
- POST logout has CSRF and session invalidation. Signup/recovery responses are generic for existing/missing accounts. Different timing is not an enumeration guarantee and must be assessed before launch.

## Unresolved / not marked complete

The 12–4096-byte password policy, one-hour credential expiry, launch-v1 terms identifier and limits are provisional technical policies pending DEC-09/product approval. Terms text/link requires legal content. Idle session timeout remains unspecified. Account lifecycle administration/audit, public/private profile editing, Google sign-in, organization scope enforcement and mail delivery remain separate tasks. AUTH-01/02/04/05 are intentionally not checked complete by this evidence.

## 2026-10-10 implementation checkpoint

Private and public profiles now have distinct Application views. Public profiles omit email and private preferences and list only published race bibs. Private edits use the authenticated account, optimistic version checks and an atomic audit append; submitted account IDs cannot select another profile. Optional email and in-app choices are independent, default false, and recorded together in the preference audit. Account suspension/reactivation checks current authoritative administrator roles, increments session version, consumes outstanding credentials and appends an audit in the same transaction. Anonymized accounts cannot be reactivated by this path.

Observed focused validation:

- `docker compose --env-file .env.docker exec -e APP_ENV=test php vendor/bin/phpunit tests/Identity/PrivateProfileHttpTest.php tests/Identity/ProfileLifecycleTest.php`: green, 6 tests / 78 assertions. The new in-app checkbox first failed as absent; DTO, persistence, form and audit integration then passed. Missing profile rows and unpublished-race privacy each had observed failing behavior before correction.
- `... phpunit tests/Identity/IdentityHttpTest.php --filter testInvalidSignupAndRateLimitAndGenericRecovery`: green, 1 test / 21 assertions after isolating the client limiter key with a synthetic unique IP. Production limiter settings are retained; no global Redis clearing.
- Focused PHPStan level 10 on Identity Domain/Application/Infrastructure/controllers/tests: no errors after preference implementation (before the latest test-only IP isolation).
- Earlier combined Identity/Inventory run: 56 tests / 383 assertions with one limiter failure; 55 scenarios passed. Do not treat that combined run as green. A rerun and global coverage/mutation gates remain required.

Verification resend is generic for unknown/already-verified accounts. Issuance locks and rechecks the authoritative account; a real two-connection suspension/credential-issuance race proved no token or mail intent can be issued after suspension wins. Verification consumes all outstanding verification links and replay is denied. Reset replaces the hash, consumes all reset links and invalidates existing sessions. Runtime mail delivery is implemented separately by Operations; this evidence does not certify external provider acceptance.

Google sign-in, legal terms approval, idle-session policy, final production mail/provider acceptance and privacy anonymization orchestration remain unfinished. Previous checkpoint statements about missing profile/lifecycle screens are superseded by this section; task completion still requires final quality evidence and any external acceptance criteria.

The combined focused rerun on 2026-10-10 is now green: `... phpunit tests/Identity tests/Inventory`, **56 tests / 385 assertions**. This supersedes the previous limiter-failing combined run. Coverage generation is being rerun separately; no updated coverage percentage is claimed here yet.
