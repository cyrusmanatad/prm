# Compact administration Jobs and health and Data cleanup evidence

**Scope (2026-11-26):** the administration Jobs and health page (`/admin/operations`) and the Data cleanup pages (`/admin/cleanup`, a batch and a dry run), following the compact pattern the user approved for the other administration pages ([compact Brand and Privacy requests](compact-review-queues-evidence.md)). These were the last administration pages on the earlier layout. Branch `ui/admin-operations-cleanup` from `origin/main` at `05de146`. Tested on the local Compose stack (`weavdash`) with rebuilt `app`, `migrate`, `worker`, `scheduler` and `acceptance` images, in Chromium only.

Cleanup stays dry-run only (D32/D33): no deletion or execution was added.

## Change

- **Jobs and health:**
  - A compact header with the observation time and the read-only statement, and Refresh observations, which is disabled while it reloads.
  - Summary tiles: services down or degraded (and how many are not observed), background queue depth (Unknown, with "Redis did not answer", when Redis is unreachable), notification outbox (the tile opens uncertain sends when there are any) and failures in the last 24 hours (with the total retained).
  - Compact Services and Failed jobs cards. The separate Queues table is replaced by the tiles. Failed jobs still show only the job type, queue, error class and time; payloads and messages stay withheld.
- **Data cleanup list:**
  - "New fixture batch" opens a dialog with the existing name and purpose fields. Errors stay beside the fields and a new batch opens its own page.
  - The batch table adds a status from the latest dry run: No dry run, Out of date, "N blocked" or Nothing blocked. Chips with counts filter it in the browser, because the list is unpaged (newest 100), like Platform access.
  - The Overview's "batches have blocked records" item now opens `/admin/cleanup?view=blocked`.
  - The three cleanup rules are kept as fine print under the table.
- **Batch:** a compact header with the reason, creator and date, plus Preview — no data deleted, Mark a record and Run a dry run. Run a dry run stays disabled until something is marked.
  - Marking happens in a dialog with the same fields and errors.
  - Marked records have Workspaces and Accounts chips, and Remove from this batch sits in the row menu. As before, there is no confirmation, because removing a mark deletes nothing and marking again undoes it.
  - The Dry runs table shows Current or Out of date.
  - Rule errors on a dry run (for example an empty batch) now appear as a banner; before, they were not shown.
- **Dry run:** summary tiles for eligible, blocked (highlighted when non-zero), protected and marked records. The manifest has disposition chips, the out-of-date warning is unchanged, and the Execution card holds the "cannot be executed" notice and the copyable reference.
- **Components:**
  - Added `features/admin/FormDialog.vue`, a shared compact form dialog: first field focused, focus contained, Escape and Cancel close it except while saving, the trigger is restored and the first invalid field is focused. Both cleanup forms use it.
  - Added `features/admin/cleanup.ts` with the batch status rule.
  - Reused `FilterChips`, `DataTable`, `RowActionMenu`, `StatusBadge`, `EmptyState`, `UnavailableNotice`, `CopyReference`, `FormField`, `SelectField`, `BackLink` and `DialogClose`, with `rec-card`, `rec-stats`, `rec-table` and `rec-dialog`.
  - `admin.css`: the linked-tile styles now apply to any admin `rec-stats` tile, not only the Overview's.

## Backend contract

Migration `2026_11_26_000001_add_platform_compact_cleanup` (SQL in `database/sql/platform-compact-cleanup.sql`):

- **`app_cleanup_batches`:** adds `latest_run` to each batch, either `null` or the id, time, eligible, blocked and protected counts, and `stale`.
- The signature and existing fields are unchanged. Rollback restores the definition in `platform-governance.sql`.

## Verification

| Command | Result |
| --- | --- |
| `acceptance` `--filter 'PlatformGovernanceTest\|PlatformConsoleTest'` | 22 tests, 710 assertions passed. The cleanup test now checks `latest_run` before a dry run, after it and once the marks change. A first run had one failure in the unchanged audit facet test (`PlatformConsoleTest:312`). That test searches the audit by a new account's numeric ID, which can match other rows on the shared database. It passed on the rerun. |
| Migration rollback, then migrate | The earlier function (without `latest_run`) was restored, then reapplied; `weavdash_app` keeps EXECUTE. |
| `pint --test` (changed PHP), `format:changed`, `typecheck`, `build` | Passed. |
| `npm test` | 38 files, 229 tests passed. New: cleanup list status chips, `?view=blocked`, the create dialog, and the batch's mark dialog and dry-run gating. Updated: Jobs and health tiles, and dry-run controls limited to copy and chips. |
| Browser `administration`, desktop and mobile, shared stack | First run: 29 passed, 1 skipped (planned). The cleanup journey failed on both projects because the mark dialog stayed open after a successful save: the dialog ignored `close()` while the form still reported processing. Fixed (`FormDialog` exposes an unconditional `close`; Cancel and the close button still wait). Rerun of the cleanup, Data cleanup, Jobs and health and diagnostics tests: 8 passed. |
| 44px sweep (temporary spec, deleted) | Jobs and health, Data cleanup, the new-batch dialog, a batch, the mark dialog, a dry run and Overview, at 1440 and 390 px: no target below 44px and no horizontal scroll. |

## Visual inspection

Temporary captures, outside the repository and not retained, of the pages and dialogs in the sweep at 1440 and 390 px.

- 1440: tiles in one row of four. Services and Failed jobs use the compact table, with times on the right. Batch status badges and chips are on one row. The dialogs are centred, with Cancel and the submit button in the footer.
- 390: tiles in two columns and two-line records. Dialogs fill the width with the footer visible. Dry-run tiles in two columns, and the manifest reasons sit under the type.
- Found and fixed during inspection: the Preview badge stretched to button height in the batch header, and the batch's dry-run counts showed unlabelled on phones (now "Eligible 0 · Blocked 1 · Protected 0").

## Unverified gates

- WebKit and native Safari, manual screen reader and zoom reviews (inherited).
- The pre-existing `privacy-closure` workspace-closure failure (Workspaces switch button, outside this change).
- The cleanup list's chip counts cover the newest 100 batches it returns; the list is not paged.
- Staging and production: not deployed.
