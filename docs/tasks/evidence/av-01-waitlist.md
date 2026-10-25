# AV-01 — Explicit race waitlist membership

Implemented locally on 2026-10-09. No commit or external deployment.

The Application facade `ManageRaceWaitlist` accepts the trusted authenticated account ID supplied by the composition/presentation layer; it never accepts an administrative override. `mine` returns an Application `WaitlistView`. The persistence port holds Domain membership values internally. Join requires an active verified account and a published race. Existing active membership is idempotent and preserves its explicit preferences. Leave preserves the historical row and original end instant; rejoin creates a new historical membership. Changes to ended membership preferences fail. Leave remains possible after race withdrawal. All join/leave/preferences mutations coordinate with the same race-row lock; the partial unique constraint is the final active-membership safeguard. Newsletter records have independent ownership and do not change membership.

Observed red→green vertical slices:

- `docker compose --env-file .env.docker exec -T -e APP_ENV=test php vendor/bin/phpunit tests/Integration/Availability`: red missing `ManageRaceWaitlist`, then green 1 test / 9 assertions.
- Same command with `--filter Leaving`: red missing `leave`, then entire suite green 2 / 15.
- `--filter OnlyTheOwner`: red missing `preferences`, then entire suite green 3 / 23. Another account, including an administrator, cannot mutate membership.
- `--filter Coordinate`: red because preference update completed while another real PostgreSQL connection held the race row; after coordinating mutations, entire suite green 4 / 28. The competing connection now reports SQLSTATE 55P03 under its isolated 100ms lock timeout, then succeeds once the parent releases the race lock.
- Ineligibility/withdrawal/history checks expanded the suite to green 5 / 34. Newsletter independence added afterward; final results are recorded below.

Refactoring exposed scalar authenticated account IDs and an Application view instead of exposing Domain values to Presentation. PHP CS Fixer applied the project rules. Scoped PHPStan level 10 passes without ignores/baselines; Deptrac reports zero violations, warnings and errors. The combined AV-01/NOTE-01 coverage run reported 100% core (61/61 statements) and 113/117 source statements before the final newsletter-only test; uncovered lines are defensive stored-shape guards, not excluded from coverage. Full-project gates remain the parent agent's responsibility.

The public availability publication/snapshot boundary is AV-02, and the French screens are AV-04. This evidence does not claim either of those downstream tasks is complete.

Final regression: `docker compose --env-file .env.docker exec -T -e APP_ENV=test php vendor/bin/phpunit --filter 'RaceWaitlistTest|RecipientInboxTest'` passed **16 tests / 102 assertions**. Scoped PHPStan passed after the final test typing fixes. Global Deptrac passed **0 violations, 0 warnings, 0 errors**.
