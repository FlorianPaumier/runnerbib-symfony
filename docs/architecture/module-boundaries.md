# Implemented architecture layout

Domain, Application, Infrastructure and Presentation each contain module directories for Identity, Organizations, Races, Inventory, Listings, Availability, Checkout, Sales, Finance, Support, Notifications and Newsletter.

Dependencies point inward. Domain contains plain PHP models and rules. Application owns use cases, DTOs and persistence/provider ports. Infrastructure maps domain objects through DBAL and implements external ports. Presentation invokes Application; Symfony configuration composes adapters. Empty module directories reserve the agreed layout; they do not imply implemented features.

Module ownership follows the saved domain model plan. Cross-module changes use explicit application coordination and one database transaction when atomicity is required. Domain objects do not load each other through repositories. Public discovery uses dedicated read projections; private holder data never enters public DTOs.

Doctrine migrations live in migrations/ under DoctrineMigrations, outside runtime business layers. SQL definitions live in migrations/sql/. They install the consolidated 40-table persistence contract, not the earlier 64-table responsibility inventory. Database constraints enforce local integrity; aggregate totals, authorization, provider effects and lifecycle coordination require future application use cases.

Open business policies remain open. No refund ownership restoration, guessed cutoff, evidence retention duration, registration-field vocabulary or provider recovery execution is implemented by a migration. Optional daily analytics remain outside the 40-table scope.

Architecture enforcement remains deptrac.yaml and the coverage/quality gates. New classes are added only with behavior through agreed public test seams.
