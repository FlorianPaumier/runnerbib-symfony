# RelaisDossard design direction — Take the start / v3

Design: [Figma v3](https://www.figma.com/design/6yIGSdydLaTODUtM8GoC5J?node-id=22-204).
Updated 2026-10-17. This replaces the rejected split-hero layouts; the design is now implemented in the Symfony/Twig application. Specifications and the glossary govern behavior.

## Idea and architecture

The bib passing between runners becomes the page’s movement and its narrative.
Compose full-width chapters: photographic mosaic → compact offer discovery → bib handoff
→ questions → next start. Every chapter owns its composition; do not revert to
photo beside headline, three feature cards, and an inset CTA band.

Use the existing integrated Twig/Symfony UX/Tailwind stack when implementation is
requested. Microfrontends are deployment architecture, not a design requirement.

## Reference study

- [Nomu](https://nomu.store/): central statement and animated product staging.
- [Dropship](https://www.dropship.io/): product content becomes part of the hero.
- [Listed Wines](https://listedwines.com/): moving inventory and editorial type.
- [Atomato](https://www.atomato.co/): decisive identity and integrated product examples.
- Reelmotion could not be inspected because the site failed SSL negotiation.

The earlier [Airbnb analysis](https://github.com/VoltAgent/awesome-design-md/blob/main/design-md/airbnb/DESIGN.md)
remains background for marketplace clarity, not a mandate for its page layout.
The art-direction draw selected Colour chapters, seed 863098238, with Albert Sans
and a mosaic masthead. The supplied references inform composition, not copied assets.

## Palette and proportion

| Token | Value | Role |
| --- | --- | --- |
| `--ink` | `#111715` | Masthead, close and primary text |
| `--paper` | `#F7F8F3` | Reading surfaces and text on dark |
| `--soft` | `#E8EBE3` | Neutral distance and question chapters |
| `--lime` | `#D8FF3E` | Primary action, selected states and limited campaign accents |

The full yellow-green distance chapter was rejected as excessive. Keep the
large surfaces neutral. Lime is an accent, never the default section background.
Use ink text on lime. Use flat fields and hard chapter changes, without gradients,
decorative rules, resting shadows, or repeated rounded panels.

## Type and grid

Use Albert Sans: 600 for display, 400 for body, 600 for controls. Two-line hero:
“Reprenez / le départ.” Desktop display is about 144/136px, phone 64/61px.
Chapter headings are 88/84px desktop and 44/44px phone; body 18–20px with 1.5
line height. Keep controls at least 48px, primary actions 56px, square corners.

Desktop gutters are 32px; phone gutters 24px. The mosaic uses six columns on
large screens and three on phones. Four photographic crops share one monochrome
treatment. Keep imagery separate from text. The current campaign images are
AI-generated concept assets, not evidence of real events, customers or inventory.

## Interaction and motion

- Distance links update the accessible selected state and search destination.
  Offer cards show recent real listings; selecting a distance prepares the filtered
  race search, without claiming that the displayed cards match that filter.
- Use the controller’s actual `distance_min` and `distance_max` integer filters.
  Half-marathon search spans 21097–21098m to include integer rounding conventions.
- Masked headline: 900ms ease-out, 110ms between lines.
- Section parts reveal independently on entering the viewport, 720ms, 24px travel.
- The bib moves between runners; an image settles slowly. No decorative loops.
- Hover and press give short feedback. Preserve visible keyboard focus.
- Reduced motion keeps all content visible and removes travel and transitions.
- Stimulus supplies selection and viewport reveals; native details supply expandable answers.
- The latest offer-card section is implemented in the app; Figma synchronization
  remains pending because the connected Starter account reached its tool quota.

## Product integrity and delivery boundary

Launch copy remains French. Preserve verified-listing meaning, authoritative
payment confirmation, separate waitlist window and checkout lock, and per-bib fee
calculations. Public pages never expose proof documents or private runner data.

Implementation: `templates/home/`, `frontend/landing.css`, and the landing Stimulus
controller. The homepage composes existing offer-access and fee rules through
`DiscoverListingOffers`; no schema changes or new runtime dependencies.
Local preview: http://localhost:8082/. Run `bun run assets:build` after CSS changes.
Use `make test coverage lint analyse cs-check` for verification with the existing
`.env.docker` configuration. Images and the OFL-licensed font are served locally.
An empty catalogue displays an honest empty state rather than demonstration offers.

Verification observed on 2026-10-17: the initial homepage test run failed for the
old heading and absent offer cards; after implementation, HomeTest passed
(3 tests, 39 assertions). Asset build, YAML/container lint, Deptrac, PHPStan and
CS checks passed. Chrome checks at 1440/768/390px passed overflow, image/font
loading, distance selection, FAQ and reduced-motion assertions. Visual inspection
returned no failures; its two warnings concern the editorial type scale and lack
of video. Still photography is intentional; no authentic campaign footage was supplied.

## Application pages

The shared application shell now uses the same ink/paper/soft palette and locally
hosted Albert Sans. `frontend/application.css` defines consistent controls, status
messages, flat surfaces, fee summaries and typography for the existing Twig pages.
`base.html.twig` keeps the homepage separate while applying this shell to all other
pages. `_navigation.html.twig` supplies desktop and native-details mobile navigation,
with a current-page indicator. No JavaScript dependency is needed for the menu.

Course discovery uses a sticky desktop filter column and distance-led result rows.
Advanced filters expand with native details; all existing query names, pagination
and error responses remain intact. Course and offer details use clear fact/fee
summaries. Authentication puts its form first in document/mobile order and retains the neutral
editorial panel on the left on desktop; account, seller,
organization, notification, waitlist, newsletter and privacy pages reuse the shell.
Import results scroll within a labelled keyboard-accessible region on small screens.

Motion is limited to short entrances and control feedback, with reduced-motion
support. Catalogue pages deliberately avoid campaign imagery that could imply a
synthetic photograph depicts an actual listed race. Build with `bun run assets:build`;
no additional environment variables or runtime dependencies are needed.

Verification on 2026-10-19: the 390×667 login check first failed because the
submit button ended at 779px, then passed after reordering the template. Login, signup and recovery also passed
at 320×667, and the desktop split composition was retained. Account
and race HTTP tests passed (18 tests, 167 assertions). Browser checks passed
filtering, native mobile navigation, login, race details, account, cart,
notifications and privacy at 390/768/1440px. Twig/YAML/container lint, Deptrac,
PHPStan, formatting and asset compilation passed. The full regression suite passed (768 tests, 4,701 assertions). The coverage run also passed all 768 tests (4,716 assertions), with
98.04% overall line coverage (2,756/2,811) and 100% Domain/Application
(950/950). Both enforced gates passed. No coverage exclusions or thresholds changed.

## Upstream license

MIT License

Copyright (c) 2026 VoltAgent

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
