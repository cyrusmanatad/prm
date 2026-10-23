# M2 Creator CRM Status

Status: **development complete; automated gates passed; manual release acceptance pending**. **Confirmed (D19):** M2 development may continue while manual M1 screen-reader, browser-zoom, and native Safari reviews remain pending. These reviews remain release gates; no production readiness is claimed.

## First slice: private creator pipeline

Implemented and verified for this first slice:

- Workspace-private lead capture, embedded primary contact name/email, private notes, exact money values, and next-action dates.
- Seven **PDF** stages: Lead, Pitched, Negotiating, Onboarded, Active, Repeat Client, Lost. Accessible stage menus persist changes and append a private activity event.
- **Proposal / implementation default:** members, managers, admins, and owners may edit CRM data; viewers can read only. Stage changes are manual between the seven source stages. No stage triggers onboarding, sending, or automation; D20 authorizes only explicit Start onboarding.
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

## Manual outreach template slice

Implemented locally: workspace-private reusable plain-text templates with create/edit/delete, version conflicts, redacted audit and forced RLS. The composer merges brand/contact/creator names literally, requires correction of unresolved placeholders, supports separate draft editing and manual copy, and sends nothing. A pipeline link opens the template screen. Field choices and permissions follow the documented **Proposal / implementation defaults**. See the [template contract](m2-outreach-template-contract.md).

Verification on the working tree based on `e6c54bf`: 23 identity tests (200 assertions), all 15 PostgreSQL tests (259 assertions), the new five-role/revocation acceptance test (25 assertions), and 18 Vue tests passed. Type checks, build, Pint, standalone DDL and source secret scanning passed. A separate disposable database passed full migrations, template rollback/reapply, grants and all three template integration tests (34 assertions), then was removed. Both Chromium desktop/mobile template journeys passed, including stale-tab preservation, literal merge, draft editing/captured clipboard copy, deletion, viewer mode and revocation. All four existing Chromium pipeline regression journeys also passed. Both template axe scans had zero violations/incomplete results; screenshots were visually reviewed and overflow checks passed. See [template evidence](evidence/m2/outreach-templates.json). These results do not extend historical remote CI evidence to the current working tree. WebKit/native Safari, actual clipboard integration and manual assistive-technology/zoom review remain pending.

The first broader PostgreSQL run encountered a duplicate retained Faker email in an existing pipeline test. Factory-generated synthetic emails now use UUIDs, avoiding process-local Faker uniqueness collisions across repeated runs. The complete suite passed after this change.

## Reusable contacts and dedicated reminders

Delivered: private reusable contact records, many-lead linking/unlinking, contact editing/deletion rules, and dedicated reminder create/edit/delete, completion, reopening and real future snooze. Lists use literal contact search, reminder status/due filters and independent 25-row cursors. Composite ownership constraints, locked versions, forced RLS and redacted audit cover all three new tables. The browser preserves unrelated contact drafts across linking and reminder commands. See the [CRM contract](m2-crm-contract.md) and [focused evidence](evidence/m2/crm.json).

The combined fresh-setup revision passed 24 identity tests (208 assertions) and 22 PostgreSQL tests (392 assertions), including the M3 history-retention guard on lead deletion. Dependency advisories, type checks, 21 Vue tests, build, full Pint, standalone DDL and source secret scanning pass. A fresh disposable database rolled back/reapplied all four M2/M3 migrations, reapplied runtime grants, passed ten targeted integration tests (174 assertions), then was removed. The combined fresh setup passed in 703 seconds, including 46 Chromium/WebKit browser tests (two intentional duplicate skips), 21 acceptance tests (510 assertions), and 76 axe scans with zero violations/incomplete results. See [combined evidence](evidence/m3/README.md) and final [CRM desktop](evidence/m2/final-crm-desktop.png)/[mobile](evidence/m2/final-crm-mobile.png) captures. The later readable-history and manual social-profile refinements have separate four-project verification. The final profile PostgreSQL suite passed 22 tests with 399 assertions.

## Acceptance and handoff

M2 feature acceptance criteria are implemented: seven persisted stages, reusable contacts, manual activity/templates, real next-action/reminder snooze, completion, currency-separated derived totals and concurrent-edit rejection. D20 authorizes continuing through M3 while carrying pending manual release reviews. M3 implementation is delivered; see [M3 status](m3-status.md). Neither milestone implies production readiness.

Manual screen-reader, actual full browser zoom and native Safari reviews remain pending from M1. External reminder delivery remains M5, rather than unfinished M2 behavior. D20 settles the initial explicit onboarding trigger and minimal collection; later automatic deal transitions and production retention choices remain open.

The development stack remains `weavdash-m1` at `http://localhost:8000`, with PostgreSQL, Redis and private-network ClamAV. Use disposable databases/projects for destructive probes. M4 has not started; recover its financial and entitlement decisions before a later continuation.
