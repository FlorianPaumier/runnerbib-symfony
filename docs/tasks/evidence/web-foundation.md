# BASE-02 web foundation progress — 2026-10-08

Status: in progress; Cloud configuration in DEC-09 remains pending. The user authorized execution of all tasks through their public boundaries.

Implemented Symfony 8.1 Twig/AssetMapper, Stimulus and Turbo dependencies, French locale/shared accessible layout, and Tailwind 4.3.3 assets through locked Bun 1.3.1 builds. Domain is excluded from Symfony service autodiscovery. French home, real public race search/filter/results/detail and controlled error pages now invoke Application queries. Published-only detail and cursor navigation are tested against isolated PostgreSQL. Identity specialist is adding real signup/login/reset screens.

Development uses `make assets` (containerized Bun, separate node_modules volume). Production builds CSS in an assets stage, installs importmap dependencies, compiles AssetMapper metadata, and copies compiled assets into Nginx. `make up` builds assets before starting the stack. `make assets-watch` uses local Bun during template development.

Observed verification:

- `bun run assets:build`: compiled Tailwind CSS.
- `bin/console lint:twig templates`: one valid template.
- Both Compose configurations parse; development and production images build.
- Isolated project runnerbib-assets-smoke started all three production services healthy.
- Nginx served versioned styles/app.css (HTTP 200, 7320 bytes) and app.js (HTTP 200, 61 bytes).
- Isolated /health and /ready returned HTTP 200, ok and ready.
- Isolated services/network stopped; their volumes preserved. Existing application volumes were not reset.
- Existing PHPUnit suite still passes 25 tests/88 assertions. Runtime line coverage remains 100% over the small bootstrap; this is not web-journey coverage.

Additional executed evidence: home HTTP test 1/8 assertions; race pages 10/47 assertions before cursor journey addition. Browser inspection confirmed all French labeled filters and empty state; corrected the race-name input width after visual review. Root is rebuilding CSS and production assets after the new screens.

Remaining: mobile/keyboard browser checks, final combined quality and rebuilt production image smoke after security/queue additions. Local asset tooling is selected; Cloud region/resource/session/queue/storage validation in DEC-09 and BASE-06 remains open.

Responsive browser observation: at a 390×844 viewport the document width was 375px; every search input was 287px wide, with no horizontal overflow. French labels, native date controls, navigation and empty state were visible. Temporary viewport override was reset. This is a responsive smoke check, not a WCAG audit.

Production snapshot rebuilt successfully after Symfony Security/Messenger additions and `pcntl` extension. Both Compose configurations parsed. An isolated `runnerbib-execution-smoke` stack using the built production images started healthy on localhost:8094. Production cache was cleared before migration; both then-current forward migrations applied to its own database. `/`, `/courses`, `/connexion` and `/ready` returned HTTP 200 with debug disabled. The isolated services/network were stopped and their volumes preserved. Later source changes still require a final build snapshot; this smoke does not establish Cloud deployment or production-role provisioning.
