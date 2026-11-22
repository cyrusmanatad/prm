# Compact administration Brand requests and Privacy requests evidence

**Scope (2026-11-25):** the administration Brand requests (`/admin/brand-requests`) and Privacy requests (`/admin/privacy-requests`) queues, following the compact pattern the user approved for the other administration pages ([compact Platform access](compact-platform-access-evidence.md)). Branch `ui/admin-review-queues` from `origin/main` at `8c9931a`. Tested on the local Compose stack (`weavdash`) with rebuilt `app`, `migrate`, `worker`, `scheduler` and `acceptance` images, in Chromium only.

## Change

- **Brand requests:**
  - A compact card with status chips and counts: All, Awaiting decision, Waiting for applicant, Approved and Declined. They replace the status dropdown.
  - Search, plus a Source dropdown that applies as it is chosen, and the applied-filters line.
  - The table shows the Brand and submitted date, status, applicant (email hidden on phones), source and last change, with numbered pages. The queue order is unchanged: awaiting a decision first, longest wait first. The request detail page is unchanged.
- **Privacy requests:**
  - Summary tiles: awaiting review (with unassigned), review recorded, cancelled and all requests.
  - The stacked cards became a compact table showing the closure and its scope, status, requester (email hidden on phones), handler and earliest erasure, with numbered pages (20 per page, open requests first, as before).
  - Each row title opens `PrivacyRequestDrawer`, named "<subject> — request <id>" like the card it replaces. It holds the details, the closure impact counts and the handling form, now shown directly instead of behind a disclosure. The form keeps its fields and labels: action, reason, verified reference for a cancellation, password and fresh code. Save request update sits in the drawer footer.
  - The drawer stays open on errors and closes when the update is saved. Nothing here erases records.
  - `PrivacyReviewCard` was removed; `features/privacy/review.ts` holds the shared row type and status.
- **44px targets across the compact admin pages:** the `brand-approval` browser test checks the console's 44px touch-target rule. It found the compact chips (32px) and row-title links (19px) below it. These styles came in with PRs 78–82, whose specs did not measure targets. `admin.css` now gives compact chips, row titles, card-header links and links in table cells at least 44px. A sweep with the same check on Overview, Accounts, Workspaces, Notifications, Audit, Plans, Assignments, Support access, Platform access, Brand requests, Privacy requests and the privacy drawer, at 1440 and 390 px, found nothing below 44px. Rows are a little taller as a result.
- **Times:** `recentTime` falls back to date and time without the zone (full time in the tooltip), so older entries no longer wrap. This affects every page that uses it: Overview, Notifications, Audit and Brand requests.
- **Components:** reused `ListToolbar`, `AppliedFilters`, `FilterChips`, `DataTable`, `Pagination`, `StatusBadge`, `EmptyState`, `DetailList`, `ClosureImpact`, `DialogClose`, `containDialogFocus` and `useValidationFocus`, with `rec-stats` and `rec-drawer`. Added `adm-drawer-heading` in `admin.css`.

## Backend contract

Migration `2026_11_25_000001_add_platform_compact_review_queues` (SQL in `database/sql/platform-compact-review-queues.sql`):

- **`app_platform_brand_requests`:** adds `facets`, a count per status that follows the search and source but not the status filter. `pending` is kept.
- **`app_privacy_review_list`:** adds `summary` (awaiting, reviewed, cancelled, unassigned): counts only, no manifests or content.
- Signatures and existing fields are unchanged. Rollback restores the definitions in `brand-approval.sql` and `privacy-closures.sql`.

## Verification

| Command | Result |
| --- | --- |
| `acceptance` `--filter 'PlatformConsoleTest\|PlatformGovernanceTest\|BrandApproval\|Privacy'` | 60 tests, 1,457 assertions passed. New: `test_review_queues_report_status_counts_without_private_content`. |
| Migration rollback, then migrate | The earlier functions were restored, then reapplied; `weavdash_app` keeps EXECUTE on both. |
| `pint --test` (changed PHP), `format:check:changed`, `typecheck`, `build` | Passed. |
| `npm test` | 38 files, 227 tests passed. New: Brand status chips (counts, current chip, hrefs), and the privacy summary tiles, rows, drawer name, handling form and save request. |
| Browser `administration`, `privacy-closure`, `brand-approval`, desktop and mobile, shared stack | First run: 32 passed; `brand-approval` failed its 44px touch-target check on the new Brand chips and row titles, which led to the fix above. After the fix, with `brand-lead-visibility` added: 37 passed, 7 skipped, none failed. The skips are `privacy-closure` without `CLOSURE_TEST_COMPOSE`, plus the planned desktop skips. |
| Browser `privacy-closure`, desktop and mobile, disposable `weavdash-closure` stack (port 8013, own database, runtime grants applied; removed afterwards) | 4 passed: personal closure, and the operator assign and review through the new drawer, on both projects. 2 failed: "workspace closure revokes publication…" waits for `aria-pressed` on the Workspaces page's workspace button, which the earlier Workspaces redesign (`2afa32c`) removed. That page is unchanged here, and its evidence records that `privacy-closure` was not run then. A separate task is suggested. |

The `privacy-closure` operator test now opens the request from its row, checks the drawer and accessibility, assigns, reopens after the redirect, records the review and checks the row shows Review recorded.

## Visual inspection

Temporary captures, outside the repository and not retained: Brand requests and Privacy requests at 1440 and 390 px, and the privacy drawer at 1440 and 390 px. The touch-target sweep is described above.

- No page-level horizontal scroll.
- 1440: chips, toolbar and tables on one row each; the drawer sits on the right with Close and Save request update in its footer.
- 390: two-line records; the drawer fills the screen with its footer visible.
- Found and fixed during inspection: older "Last change" times wrapped (now without the zone), and the drawer's help text sat too close to the Review action field.

## Unverified gates

- WebKit and native Safari, manual screen reader and zoom reviews (inherited).
- The pre-existing `privacy-closure` workspace-closure failure (Workspaces switch button, outside this change).
- A state filter for privacy requests (the summary counts are shown; the list function keeps its page-only signature).
- Remaining administration pages (Jobs and health, Data cleanup) still use the earlier layout.
- Staging and production: not deployed.
