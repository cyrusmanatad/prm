# Compact partner pipeline evidence

**Scope (2026-11-20):** the partner My leads pipeline board, as recorded in the [portal UI alignment](ui-alignment.md). Branch `ui/compact-pipeline` from `main` at `00c002b`. Local Compose stack with a rebuilt `app` image; Chromium only.

## Change

- One stage filter: `FilterChips` with counts for All and all seven stages replaces the All leads / Repeat clients / Lost buttons and the Stage filter dropdown.
- `Pipeline::listing` adds `stageCounts`, counted under the search and due filters but not the stage filter; `counts` and totals are unchanged. Older callers fall back to `counts`.
- No Apply filters button: search (debounced, Enter applies), chips and Due next actions apply when used. Clear filters is a link shown only when filtered. Controls use `--wd-table-control` (36px, 44px on phones and touch screens).
- Lighter header (20px value). Board columns have a 150px minimum and tighter gaps, so five columns fit beside the sidebar at 1280px; compact cards; six leads per column, then `Show N more` (drops still land on a collapsed column).
- Also fixes `PortalTest`, which still asserted that `/brand` never shows an invited email. The check now allows the address only as the inviting brand's own invitation recipient and asserts that another brand cannot see it. That test had been failing on `main` since PR #52.

## Verification

| Command | Result |
| --- | --- |
| Full `phpunit -c phpunit.pgsql.xml` and `rls.sql` in a disposable Compose project (fresh volume, removed afterwards) | 35 tests, 649 assertions passed; `RLS_CHECKS_PASSED`. |
| `format:check:changed`, `typecheck`, `npm test`, `build` | Passed; 157 component tests (2 new in `PipelineBoard.test.ts`). |
| Browser: `pipeline`, `pipeline-filtering`, `pipeline-drag`, `partner-navigation`, `ui-refinement`, `professional-ui`, `portals`, desktop and mobile | 28 passed. |
| Rerun after the mobile header alignment fix: `ui-refinement`, `pipeline-filtering` | 4 passed. |

- **Rendering:** inspected at 1280 and 390px. All five columns fit at 1280px; on phones the header value block reads from the left, and chips, search and the due toggle stack with 44px targets. Temporary captures were deleted.
- **Not verified:** WebKit/Safari; staging.
