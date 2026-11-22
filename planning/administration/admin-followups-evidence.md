# Administration follow-ups evidence: Workspaces test, closure cancellation confirmation, paged Platform access

**Scope (2026-11-27):** three follow-ups from the compact administration work. Branch `ui/admin-followups` from `origin/main` at `05de146` (independent of the open Jobs and health and Data cleanup PR). Tested on the local Compose stack (`weavdash`) with rebuilt images, plus a disposable `weavdash-closure` stack for `privacy-closure`, in Chromium only.

## Change

- **`privacy-closure` workspace closure test:** the Workspaces redesign (`2afa32c`) replaced the pressed-toggle workspace buttons with Switch buttons. A current workspace shows a Current badge, its hub link and a "Current workspace" panel named after it. The UI kept the state, but the test still waited for `aria-pressed`. It now waits for that panel (a region named after the workspace), as `adversarial.spec.ts` does. No UI change.
- **Cancelling a verified closure request needs confirmation (Confirmed, user decision 2026-11-27):** in the admin Privacy requests drawer, choosing "Cancel verified closure request" and saving opens the shared Cancel-first `confirmAction` with the danger tone. The dialog names the account or workspace and the consequence: erasure will not go ahead and eligible access is restored, but publication, links, partnerships and cancelled work do not resume. The drawer stays open underneath, so server errors appear beside the fields. Assign and Record review are unchanged and need no confirmation. The requester's own cancellation on `/account/closure` is unchanged (password and code).
- **Platform access is paged on the server:**
  - 25 per page with numbered pages.
  - Chips with counts (All, Active, No authenticator, Revoked); the counts follow the search but not the chip.
  - Search on Enter, and the applied-filters line.
  - Ordering: your own grant first, then a pinned operator, then active grants by name, revoked last. The operator reached from an account page (`?account=`) or just changed (the save now redirects to `?pin=<id>`) is pinned and noted "Pinned to the top of this list".
  - Reused `ListToolbar`, `AppliedFilters`, `FilterChips` (link mode) and `Pagination`.
  - The grant, change and revoke dialog is unchanged.

## Backend contract

- Migration `2026_11_27_000001_add_platform_operator_list` (SQL in `database/sql/platform-operator-list.sql`) adds `app_platform_operator_list(filters jsonb, page_number integer)`. It takes `q`, `view` and `pin`, and returns `total`, `facets` and 25 `rows` with the same fields as before. EXECUTE is granted to `weavdash_app` only. `down()` drops it.
- `app_platform_operators()` is unchanged.
- `/admin/operators` accepts `q`, `view` (`active`, `unenrolled`, `revoked`), `pin`, `account` and `page`, and returns `operators.{total,facets,rows}`, `filters`, `page` and `pinned`.

## Verification

| Command | Result |
| --- | --- |
| `acceptance` `--filter 'PlatformConsoleTest\|PlatformGovernanceTest\|Privacy'` | 49 tests, 1,082 assertions passed. New: `test_platform_access_is_searched_counted_and_paged_on_the_server_with_a_pinned_operator`. Fixed: the audit facet assertion searched by a numeric account ID, which also matched browser-test emails containing the same digits and failed intermittently on the shared database. It now searches by a unique synthetic operator name. |
| Migration rollback, then migrate | Function dropped, then recreated; `weavdash_app` has EXECUTE and PUBLIC does not. |
| `pint --test` (changed PHP), `format:changed`, `typecheck`, `build` | Passed. |
| `npm test` | 38 files, 227 tests passed. Operator tests now cover server pages, chip links and counts, and the pinned note. The privacy drawer test checks that a cancellation sends nothing until it is confirmed. |
| Browser `administration`, desktop and mobile, shared stack | 31 passed, 1 skipped (planned). |
| Browser `privacy-closure`, desktop and mobile, disposable `weavdash-closure` stack (port 8013; removed with `down -v`, scratch files deleted) | 6 passed, including the previously failing workspace closure test. The operator test now also cancels the request: Cancel has focus first, axe is clean, Escape returns focus to Save, then the cancellation is confirmed. |
| 44px sweep (temporary spec, deleted) | Platform access page 1 (pinned), page 2, a search and Revoked, at 1440 and 390 px: no target below 44px, no horizontal scroll, 25 rows each. |

## Visual inspection

Temporary captures, outside the repository and not retained, of Platform access at 1440 and 390 px. The shared database holds about 1,440 grants left by test runs: 58 pages, with counts per chip. Your own row is first, with its note. On phones the rows are two-line records and the pager wraps under its summary.

## Unverified gates

- WebKit and native Safari, manual screen reader and zoom reviews (inherited).
- The truncated "This is you" suffix on your own row's email at 1440 (unchanged from the earlier Platform access page).
- Staging and production: not deployed.
