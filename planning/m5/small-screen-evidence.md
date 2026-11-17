# M5 small-screen acceptance

Scope: the small-screen checks carried to Claude under D28 for M5-02 to M5-06 (onboarding notices and outcomes, schedules and preferences, scheduled delivery, follow-up drafts and the approval inbox, logged replies). Date: 2026-11-17. Revision: branch `ui/m5-small-screen`, based on `main` at `d237d13`. Environment: disposable local Compose project `weavdash-admin` (`localhost:8010`; PostgreSQL 16, Redis, array mail, no worker and no ClamAV service). No shared, staging or production system was touched and no email was sent.

Status: **small-screen acceptance passed locally in Chromium** for M5-02 to M5-06. Provider delivery (M5-09) and the inherited manual reviews stay open.

## What was checked

The six M5 journeys previously forced a 1440 px viewport in every project, so the mobile project never exercised a phone layout. They now run the whole journey at 390 px in the mobile projects and review the page at 390 and 320 px; desktop projects keep 1440 and 1920 px. At the phone widths each journey asserts no axe violations, no page-level horizontal overflow, and no control under the 44 px touch target (`smallTouchTargets` in `tests/Browser/helpers.ts`; links inside running text are exempt, and a checkbox is measured with its label).

## Findings and fixes

All interactions already worked at phone widths: no overflow and no axe violations were found before any change. The first run failed only on touch targets; the remaining items come from visual inspection.

| Finding | Fix |
|---|---|
| "Back to workspaces" (Automation) and "Back to leads" (onboarding review) were 27 px high. | Same 44 px link treatment as the workspace support page. |
| The email and submission-notice checkboxes on onboarding forms had 21 px labels. | Use the shared `wd-checkbox` (44 px row, 18 px box). Also applies where lead capture reuses these fields. |
| Onboarding review: field names and values (Client, Contact, Website, Brief) looked identical; an empty request showed blank lines and a lone "·". | Use the shared `wd-detail-list` styling and show "Not provided" for empty values. The same list styling is used inside each submission version. |
| Onboarding review: "Replacement invitation" and "Submission history" rendered as body text; an empty history showed a bare heading; the version disclosure was a small target; the email history list had a stray indent and no separation. | New shared `wd-subheading`; an empty-history line; padded disclosure summary; history rows separated by rules. |
| Automation: items in the approval inbox, saved schedules and email history had unstyled titles and 24 px between every line, so items ran together on a long phone page. | New shared `wd-record` (bold title, 8 px rhythm, rule between items), modelled on the existing finance record. |
| Recipient onboarding form: the status line touched the first card. | Added spacing below it. |

`wd-subheading` and `wd-record` are added to `foundation.css` for reuse. No business logic, validation, permissions, data contracts or visible wording were changed, apart from the "Not provided" and empty-history text. The record and detail-list changes also apply at desktop widths.

## Commands and results

| Command (from `src/`) | Result |
|---|---|
| `npm run format:check:changed` | Passed |
| `npm run typecheck` | Passed |
| `npm test` | 23 files, 120 tests passed |
| `npm run build` | Passed |
| `PLAYWRIGHT_BASE_URL=http://localhost:8010 COMPOSE_PROJECT_NAME=weavdash-admin npx playwright test automation.spec.ts scheduled-delivery.spec.ts follow-up-drafts.spec.ts logged-reply.spec.ts onboarding-lifecycle.spec.ts onboarding-notifications.spec.ts approval-basis.spec.ts delivery-operations.spec.ts onboarding.spec.ts pipeline.spec.ts --project=desktop --project=mobile` | 26 passed, 2 failed. All seven tests in the six M5 journeys pass on desktop and mobile, as do approval-basis, delivery-operations and pipeline. |

The two failures are the same test, `onboarding.spec.ts:19`, on desktop and mobile: an uploaded file stays "pending" and never offers a download. This stack runs no ClamAV service and no worker, so the scan cannot complete; the failure is in the upload step and does not involve the changed markup.

The Playwright JSON reporter ended each run with `EACCES` writing `src/test-results/results.json` (root-owned directory on this host); results are unaffected.

## Rendering findings

Inspected with temporary captures (removed) at 390 and 320 px wide.

- Automation: preferences, weekly schedule, reminder and follow-up scheduling, approval inbox, composer, saved schedules and email history fit without clipping; buttons wrap one per line at 320 px; record titles are distinct and items are separated.
- Onboarding review: details read as label over value; outcome and replacement controls, confirmation dialogs and email history fit at both widths.
- Recipient return form: read-only accepted state fits at 390 px.

## Unverified gates

- `onboarding.spec.ts:19` (upload, scan and download) could not pass on this stack; it needs a stack with ClamAV and a worker, or CI.
- WebKit was not run locally (the host lacks its libraries); it runs in CI on the pull request.
- Real phones, native Safari, screen-reader and browser-zoom reviews (M1-01 to M1-03): not done.
- Provider delivery (M5-09) is separate and unchanged.
