# Organization implementation evidence

2026-10-08–09. ORG-01/ORG-02 backend implementation is verified; final shared quality gates and associated delivery acceptance remain pending. Tests use uniquely named isolated PostgreSQL databases, run the real migration command and never reset application data.

## Observed red → green

- Organization request/approval initially failed with missing ManageOrganizations; real persistence passed after the first vertical implementation.
- A runner could approve and another account could read private organization data; both tests failed to throw before authoritative role/membership guards passed.
- Adding a manager and transferring ownership initially failed with missing public commands. Owner-only membership changes, ended membership history and atomic owner transfer then passed.
- Unverified request/manager targets, pending-organization manager additions, ownerless approval and transfer to self each exposed the missing guard before the Domain policy implementation passed.
- Public dashboard membership permissions and current-organization lookup failed with missing data/methods; explicit Application OrganizationView and active-membership lookup passed.
- Race/import resource scope failed with missing scope services/methods; authoritative resource→race→organization checks passed and reject another account or absent resource.
- Required audit persistence was tested using a deliberate audit-storage outage: organization creation incorrectly succeeded (red), then rolled back its organization/membership writes after the AuditWriter integration (green).
- Suspension, rejection and manager removal initially failed with missing commands. Removal initially permitted ending the owner membership; the owner-preservation guard now rejects it.

## Additional real PostgreSQL verification

Conflicting second organization requests roll back and preserve the original active membership. A failed owner transfer rolls back both membership transitions. A second real connection with a 100 ms lock timeout receives SQLSTATE 55P03 while the organization row is locked; the current owner and membership history remain intact. Managers cannot manage membership. Approved operation requires an active verified owner. Suspended/rejected organizations cannot add managers or authorize race resources. Approval/membership actions append immutable audit within their owning transaction.

Final owned checkpoint: **97 tests / 265 assertions**, **100% (340/340) combined Races/Organizations source statements**, focused PHPStan level 10 with no errors, and focused style verification. Command: `docker compose --env-file .env.docker exec -e APP_ENV=test -e XDEBUG_MODE=coverage php vendor/bin/phpunit tests/Races tests/Organizations --coverage-clover var/coverage/races-organizations.xml`. Current shared mutation/complete quality gates remain pending; no final MSI claim is made here.

`ManageOrganizations::get/mine` return explicit authorized Application views with current memberRole/canManageMembership. Root owns French dashboard/index HTTP delivery. `OrganizationScope::forRace/forImport` protects server-side resource use; future import/export/file endpoints must invoke these checks before accessing private data. Completed CSV/file delivery is not implied by a scope guard alone.
