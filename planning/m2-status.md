# M2 Creator CRM Status

Status: **in progress; not accepted**. **Confirmed (D19):** M2 development may continue while manual M1 screen-reader, browser-zoom, and native Safari reviews remain pending. These reviews remain release gates; no production readiness is claimed.

## First slice: private creator pipeline

Implemented and verified for this first slice:

- Workspace-private lead capture, embedded primary contact name/email, private notes, exact money values, and next-action dates.
- Seven **PDF** stages: Lead, Pitched, Negotiating, Onboarded, Active, Repeat Client, Lost. Accessible stage menus persist changes and append a private activity event.
- **Proposal / implementation default:** members, managers, admins, and owners may edit CRM data; viewers can read only. Stage changes are manual between the seven source stages. No stage triggers onboarding, sending, or automation; D05 remains open for M3.
- Version checks and row locks reject stale edits, stage changes, snoozes, activity additions, and deletion. Browser errors retain unsaved fields and require explicit reload; JSON clients receive 409.
- Real future snoozing persists a UTC timestamp. Due-only filters remove the entry until its new date. UI date input/display uses the browser's local timezone.
- **Confirmed:** empty stages show a placeholder card rather than a zero count. Counts appear beside the stage name only when above zero, and placeholder text distinguishes no leads, filtered-out leads, and leads on later pages.
- Manually logged note/email/chat/call activity with the latest 50 events visible. Logging does not send a message.
- Brand search, stage/due filters, cursor pagination (25 rows), and derived totals per currency across all matching rows. Lost values are excluded; currencies are never combined. Minor-unit integers remain strings at the browser boundary.
- Separate forced-RLS entry/activity tables, same-owner composite child foreign key, redacted audit, membership locks, and tenant-scoped Inertia projections. No runtime update/delete of individual activity events; deleting a lead removes its private history and appends a redacted audit event.

## Verification

Local verification: 23 identity tests (200 assertions), 12 PostgreSQL integration tests (224 assertions), and 15 acceptance tests (341 assertions) passed. The pipeline contributes six integration tests (115 assertions) and one five-role/revocation acceptance test (29 assertions). A final literal-zero search regression check passed all six pipeline tests with 116 assertions. Strict TypeScript checks, 16 Vue tests, the application build, PHP style, the standalone DDL check, and source secret scanning passed.

A separate fresh synthetic database passed full migrations, rollback/reapply of the new migration, runtime grants, and all six pipeline integration tests; that database was removed. Four Chromium pipeline browser tests passed across desktop/mobile, including two-tab conflicts and future snoozing; two axe scans reported zero violations. Captures were visually reviewed and overflow checks passed. See [retained evidence](evidence/m2/README.md). Final remote verification passed on `c0e8627`: [backend/clean-setup](https://github.com/cyrusmanatad/prm/actions/runs/39094106344) and [frontend](https://github.com/cyrusmanatad/prm/actions/runs/39094106339). The final suite includes 12 PostgreSQL tests (225 assertions), 15 acceptance tests (341 assertions), 16 Vue tests, and 30 Chromium/WebKit browser tests with two intentional mobile duplicates skipped. All 44 axe scans reported zero violations; fresh setup completed in 364 seconds. [Remote evidence](evidence/m2/remote-ci.json) retains results and scope. Unsaved notes also survive successful snooze/activity commands and can subsequently be saved. The initial stale-tab journey exposed loss of unsaved fields; the corrected page preserves state, freezes the loaded version, and blocks saving until explicit reload.

The later empty-stage refinement passed type checks, 16 Vue tests, the build, and four Chromium pipeline journeys on the real local stack, with zero axe violations or incomplete results across the empty, filtered, one-lead, and paged states. It has not yet run in remote CI or local WebKit. See [empty-stage evidence](evidence/m2/empty-stage-ui.json).

## Remaining M2 scope

- Reusable contact records and contact management beyond an entry's embedded primary contact.
- Dedicated reminders with completion and outreach templates with safe manual merge/copy/edit. The current next-action snooze is implemented; automated reminder delivery belongs to M5.
- Full milestone journeys and acceptance review, including the carried-forward M1 manual reviews.
- Final transition/onboarding business decisions before dependent M3 behavior.

See the [implemented pipeline contract](m2-pipeline-contract.md), [M1 status](m1-status.md), [manual acceptance](m1-manual-acceptance.md), and the [roadmap](implementation-roadmap.md).
