# Compact administration Plans, Assignments and Support access evidence

**Scope (2026-11-24):** the administration Plans (`/admin/plans`), Assignments (`/admin/assignments`) and Support access (`/admin/support-access`) pages, following the Option A pattern the user approved for Overview and Accounts and then for the other lists ([compact lists](compact-lists-evidence.md)). Branch `ui/admin-access-plans` from `origin/main` at `d595de9`. Tested on the local Compose stack (`weavdash`) with rebuilt `app`, `migrate`, `worker`, `scheduler` and `acceptance` images, in Chromium only.

## Change

- **Plans:**
  - A compact card with search (`ListToolbar`) and the applied-filters line.
  - Each plan shows its version under the name, plus the workspaces using it now and the created date. Pages are numbered.
- **Assignments:**
  - View chips with counts: Current (each workspace's selected assignment, the default), Active, Scheduled, Expired, Superseded and Full history. They replace the State and History dropdowns, since every combination those offered maps to one chip.
  - The workspace filter from a workspace page stays in the applied line.
  - `AssignmentTable` gains a `compact` variant: the workspace with its assignment version, status, plan and dates. The plan detail page keeps the full table.
- **Support access:**
  - State chips with counts, with Awaiting workspace owner and Active first. They replace the state dropdown.
  - The request form still appears above the list when an operator arrives from a workspace, now in a compact card. Its fields, owner approval and suspension notice are unchanged.
  - The table shows the workspace and request date, status, operator, scope and expiry. Pages are numbered.
- **Phones:** shared two-line records.
- **Components:** reused `ListToolbar`, `AppliedFilters`, `FilterChips`, `DataTable`, `Pagination`, `StatusBadge`, `EmptyState` and `UnavailableNotice`. Added `adm-panel-body` in `admin.css` for a form card above a compact list.

## Backend contract

Migration `2026_11_24_000001_add_platform_compact_entitlements` (SQL in `database/sql/platform-compact-entitlements.sql`):

- **`app_platform_assignments`:** adds `facets` (all, current, active, scheduled, expired, superseded). They follow the workspace and plan filters but not the state and history filters.
- **`app_platform_support_list`:** adds `facets`, a count per state that follows the workspace filter.
- Signatures and existing fields are unchanged. Rollback restores the definitions in `platform-console.sql` and `platform-governance.sql`.

## Verification

| Command | Result |
| --- | --- |
| `acceptance` `--filter 'PlatformConsoleTest\|PlatformGovernanceTest'` | 21 tests, 687 assertions passed. New: `test_assignment_and_support_counts_follow_the_workspace_but_not_the_view`. It covers both functions, and a new support request counted as awaiting while the Active view is empty. |
| `acceptance` `--filter 'Entitlement\|Pilot\|Support'` | 10 tests, 223 assertions passed. |
| Migration rollback, then migrate | The earlier functions were restored, then reapplied; `weavdash_app` keeps EXECUTE on both. |
| `pint --test` (changed PHP), `format:check:changed`, `typecheck`, `build` | Passed. |
| `npm test` | 38 files, 225 tests passed. New cases cover the Assignments views (Full history current, state hrefs, compact rows), Plans search, and Support access chips with the request form and rows. |
| Browser `administration`, desktop and mobile | Mobile passed (13.7 min), covering the whole journey including these pages. Desktop timed out at 15 minutes at the account suspension step (line 282), after passing the module sweep and the Plans steps. That step is unchanged here. |

## Visual inspection

Temporary captures, outside the repository and not retained, were taken at 1440 and 390 px for Plans, Assignments and Support access.

- No page-level horizontal scroll.
- 1440: chips, tables and numbered pages on one line each.
- 390: two-line records; Support access chips wrap.
- Found and fixed during inspection: expiry times wrapped onto two lines (now date and time without the zone, with the full time as a tooltip). Plan names wrapped early on phones because space was reserved for a status badge Plans does not have (`adm-no-status`).

**Journey duration on the local stack.** The `administration` journey has slowed on every run: 7.5, 8.0, 10.2, then 13.7 (mobile) and over 15 minutes (desktop). Measured page loads on the rebuilt app stayed between 0.3 and 2.4 seconds, and the changed pages took 0.36–0.52 seconds. The local database keeps the synthetic data from every acceptance and browser run, for example 1,054 platform grants, 6,723 users and 5,677 workspaces. Pages such as Platform access grow with it, as do their accessibility scans at three widths. The timeout was not raised again. Splitting the journey (separate task) and a fresh synthetic database are the fixes.

## Unverified gates

- WebKit and native Safari, manual screen reader and zoom reviews (inherited).
- Remaining administration pages (Platform access, Jobs and health, Data cleanup, Brand requests, Privacy requests) still use the earlier layout.
- Staging and production: not deployed.
