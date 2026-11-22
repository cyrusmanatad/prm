# Compact administration Overview and Accounts evidence

**Scope (2026-11-22):** the administration Overview (`/admin`) and Accounts (`/admin/accounts`) screens, following the user-approved Option A mockups for both. The mockups were rendered at full size from the app's own stylesheets and self-hosted fonts. Branch `ui/admin-console` from `origin/main` at `ab63023`. Tested on the local Compose stack (`weavdash`) with rebuilt `app`, `migrate`, `worker`, `scheduler` and `acceptance` images, in Chromium only. The other administration pages are unchanged and follow in separate changes.

## Change

- **Shell:** `AdminShell` gains an optional `compact` header: the records-page 28px title with a one-line description and no eyebrow, used by these two pages. Other pages keep the existing heading. The console keeps its 44px targets and green focus outline (administration design handoff).
- **Overview:**
  - Six summary tiles (`rec-stats`): accounts, creator and Brand workspaces, active assignments with the expired count, queued notices with the uncertain count, and failed jobs in the last 24 hours. Each tile links to its list when the operator holds the capability. Otherwise it reads "Unavailable · Outside your capabilities", never zero.
  - The observation time is stated once, in the description.
  - Needs attention keeps every existing rule, title and next step. Failures are listed first. Each row has the status badge, the title, its next step and a short link ("Review", "View", "Inspect"). The full destination ("Review uncertain sends") is the link's accessible name.
  - Recent activity uses plain action names ("Plan assigned"), targets as "Workspace · Northline Living" or "Cleanup · 3ab9340b…", and short times ("2 min ago", "Yesterday, 20:37") with the exact time as a tooltip and `datetime`.
- **Accounts:**
  - One records card: quick-view chips with counts (All, Active, Suspended, Unverified, Platform grant), a search box that applies on Enter, Verification and Platform access dropdowns that apply as they are chosen, and a "Joined" button that opens the labelled date range with Apply dates.
  - An applied-filters line (`Applied filters` list) with Clear filters, and an "Updating results…" status while a request runs.
  - The table shows the account with its email, one status per row (Suspended, then Unverified, then Active; the account page shows both), workspaces, a Platform grant badge or a dash read as "None", and the joined date with the full time as a tooltip.
  - Numbered pagination.
- **Phones:** tiles 2 per row, attention items stacked under their badge and link, and the shared two-line records from `records.css` (account and status on top, then "1 workspace" and the date). A Filters button shows the dropdowns and the Joined button.
- **Components:**
  - Reused: `rec-stats`, `rec-card`, `rec-table`, `FilterChips`, `DataTable`, `Pagination` (numbered), `SelectField`, `StatusBadge`, `EmptyState`, `UnavailableNotice`, `DateRangeFields` and `useListFilters`.
  - `FilterChips` now formats counts with thousands separators on every page that uses it.
  - New shared admin styles in `admin.css` (`adm-stats`, `adm-attention`, `adm-toolbar`, `adm-search`, `adm-filter-set`, `adm-dates`, `adm-status`) for the remaining list pages to reuse.
  - New helpers in `features/admin/format.ts`: `auditEventLabel`, `auditTargetLabel`, `recentTime` and `dateOnly`.

## Backend contract

Migration `2026_11_22_000001_add_platform_compact_console` (SQL in `database/sql/platform-compact-console.sql`) replaces two read functions. Their signatures, gates and existing fields are unchanged, and rollback restores the earlier definitions from `platform-followup.sql` and `brand-approval.sql`.

- **`app_platform_accounts`** adds `facets`: counts of all, active, suspended, unverified and platform-grant accounts. The counts follow the search and joined dates but not the access, verification and grant filters, so each chip says what selecting it would show.
- **`app_platform_overview`** adds `target_kind` to each recent action (account, workspace, support, brand_request, cleanup, notification, from the event prefix). It also adds `target_name`: the account name, workspace name, or the support grant's workspace name, only when the operator holds `directory.read`. Otherwise the name is null and the reference stays.
- No route, permission or controller change.

## Verification

| Command | Result |
| --- | --- |
| `acceptance` `--filter 'PlatformConsoleTest\|PlatformGovernanceTest'` | 19 tests, 655 assertions passed. New: `test_account_counts_follow_search_and_dates_but_not_the_status_filters` and `test_recent_activity_names_targets_only_for_directory_readers` (names withheld from an audit-only operator). |
| Migration rollback, then migrate | The earlier functions were restored (no `facets`), then reapplied; `weavdash_app` keeps EXECUTE on both. |
| `pint --test` (changed PHP), `format:check:changed`, `typecheck`, `build` | Passed. |
| `npm test` | 38 files, 219 tests passed. New cases cover plain activity names and target labels, failures-first ordering, short link labels with full accessible names, tile links and alerts, chip counts and hrefs, one status per row, numbered pages, and dropdowns applying at once. |
| Browser `administration`, `owner-mfa`, desktop and mobile | `administration` passed on desktop (8.0 min) and mobile (7.0 min). `owner-mfa` skipped itself on both: this local stack does not enforce workspace-owner MFA. |
| Baseline before the change | `administration` passed on desktop (7.5 min) and mobile (7.3 min) with captures off. |

The `administration` journey was updated for the new Accounts controls: search applies on Enter, the date range opens from Joined and applies with Apply dates, the chips state their counts, and phones open Filters first. Its timeout rose from 8 to 10 minutes. The unchanged journey already took about 7.5 minutes on this well-used local stack, the operator list grows with each run, and the new steps pushed one desktop run past 8 minutes at the unchanged Platform access page. No assertion was removed.

## Visual inspection

Temporary captures, outside the repository and not retained, were taken at 1440, 1024, 768, 390 and 320 px for Overview, Accounts and Accounts filtered to Suspended, plus the phone Filters and Joined panel and an open dropdown at 1440.

- No page-level horizontal scroll at any width.
- 1440: six tiles in one row; attention rows on one line each; the activity table and the Accounts table match the mockups.
- 1024 and 768: tiles in two rows of three; attention rows wrap their next step under the title.
- 390 and 320: tiles 2 per row; attention badge and link on one line, then the title and next step; two-line account records with the status at the top right and emails wrapping onto two lines; numbered pagination wraps Next onto a second line.
- Found and fixed during inspection: the phone records first used a custom layout that lost to the shared records pattern, so the pattern is followed instead, with "1 workspace" in place of a bare number and the empty grant dash hidden. Chip counts lacked thousands separators.

## Unverified gates

- WebKit and native Safari, manual screen reader and zoom reviews (inherited).
- The other administration pages still use the earlier layout.
- `owner-mfa` browser journey: skipped on this stack (owner MFA not enforced); its account-search step is unaffected by the layout but was not run.
- Staging and production: not deployed.
