# UI cleanup evidence — 2026-11-01

## Scope

Local verification for branch `bel/initial` at `5df2ecf`, covering:

- `ba50f73` — `ui: rename portals, clean up layouts and drag pipeline leads`.
- `5df2ecf` — `fix: keep portal headers within narrow viewports`.

**Confirmed** decisions: [D29](../../decisions.md#customer-terminology-and-portals) (Business/Professional terminology, `/pro/*` routes) and [D30](../../decisions.md#production-portal-layout-cleanup) (production layout cleanup). The Partner portal remains a **Proposal** in its [contract](../../partner-portal-contract.md); nothing here implements it.

## Changes verified

| Area | Change |
|---|---|
| Workspaces | Cards grouped under Business and Professional with an explicit "Open … portal" action; "+ New workspace" dialog with type choices; settings and Account security side by side. |
| Account menu | Header shows first name and avatar; name and email move into the dropdown so headers stay on one row. |
| Professional portal | Navigation lists only working pages (Leads, Contacts, Templates, Onboarding, Media kit), with a menu button below 1024px and header search from 1280px. Contacts, Templates and Onboarding share the header. Summary shows real values; deferred features collapse into one "Coming soon" strip. |
| Brand portal | One-row header (Partners, Invitations); invite action beside the title; summary of active partners, pending invitations and sign-up link status; simplified partners table; invitation history always shown. |
| Pipeline | Writers drag lead cards between columns. Moves are optimistic, use the versioned stage command and stay on the board (`board` flag). The lead drawer remains the keyboard and touch path. |

Reused or extracted components: `AccountMenu`, `PartnerChrome` (now shared by Contacts, Templates and Onboarding), `FormField`, `DialogClose`, `containDialogFocus`, `AccountSecurity`, `ActionGroup`, `PipelineBoard`/`PipelineColumn`, and the new `ComingSoonStrip`.

## Results

| Gate | Command (from `src/` unless noted) | Result | Log |
|---|---|---|---|
| Formatting | `npm run format:check -- <frontend files changed since c7a5d41>` | All files pass | [format.log](format.log) |
| Types | `npm run typecheck` | Pass | [types.log](types.log) |
| Component tests | `npm test` | 12 files, 54 tests pass | [test.log](test.log) |
| Production build | `npm run build` | Pass | [build.log](build.log) |
| PHP style | `docker compose --env-file src/.env run --rm -T --no-deps app vendor/bin/pint --test` (repository root) | Pass | [pint.log](pint.log) |
| PostgreSQL integration | `… app vendor/bin/phpunit -c phpunit.pgsql.xml` (repository root) | 31 tests, 647 assertions | [phpunit-pgsql.log](phpunit-pgsql.log) |
| Unit/feature | `… app php artisan test --compact` (repository root) | 29 tests, 256 assertions | [phpunit.log](phpunit.log) |
| Browser journeys (Chromium) | `COMPOSE_PROJECT_NAME=weavdash npx playwright test --project=desktop --project=mobile` | 48 passed, 2 skipped, 0 failed | [browser.log](browser.log) |

Logs have terminal colour codes removed and the local checkout path replaced with `<repo>`; results are unchanged.

Browser skips are intentional: the session-attack journey runs once on desktop, and the drag journey is desktop-only because drag-and-drop is a pointer interaction. The browser run used local mail capture (`TRANSACTIONAL_MAIL_MAILER=array`); SMTP was restored afterwards.

During verification, an earlier full run on the pre-fix build found header overflow at 768px and 320px, a lost validation focus in the New workspace dialog, and stale spec expectations. Those were fixed in `5df2ecf` before this run.

## Screenshots

Synthetic local account, Chromium, 1440×900 desktop and 390×844 mobile. Before images predate `ba50f73`; after images are from `5df2ecf`.

| Screen | Before | After |
|---|---|---|
| Workspaces, desktop | [before](before-workspaces-desktop.png) | [after](after-workspaces-desktop.png), [dialog](after-workspaces-dialog-desktop.png) |
| Workspaces, mobile | [before](before-workspaces-mobile.png) | [after](after-workspaces-mobile.png), [dialog](after-workspaces-dialog-mobile.png) |
| Professional portal, desktop | [before](before-pro-desktop.png) | [after](after-pro-desktop.png) |
| Professional portal, mobile | [before](before-pro-mobile.png) | [after](after-pro-mobile.png), [menu](after-pro-menu-mobile.png) |
| Brand portal, desktop | [before](before-brand-desktop.png) | [after](after-brand-desktop.png) |
| Brand portal, mobile | [before](before-brand-mobile.png) | [after](after-brand-mobile.png) |

All after screenshots were inspected: one-row headers at both widths, no horizontal overflow, real summary values, a single "Coming soon" strip per portal, and the open dialog and mobile menu.

## Unverified gates

- WebKit desktop/mobile projects were not run; the host lacks WebKit system libraries (see [README](../../../README.md)).
- Manual screen-reader, full-zoom and native Safari reviews remain pending, as inherited from earlier milestones.
- Drag-and-drop on touch devices is not supported; touch and keyboard users change stages in the lead drawer.
- The synthetic reference fixture (`tests/Visual`) was not recaptured; per D30 it is historical comparison only.
- **Assumption / Open:** the outreach merge token `{{ creator_name }}` is unchanged pending a decision on migrating saved templates.
