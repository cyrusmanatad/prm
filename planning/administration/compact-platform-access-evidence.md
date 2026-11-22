# Compact administration Platform access evidence

**Scope (2026-11-24):** the administration Platform access page (`/admin/operators`), following the compact pattern the user approved for the other administration pages ([compact Plans, Assignments and Support access](compact-entitlements-evidence.md)). Branch `ui/admin-platform-access` from `origin/main` at `78861c1`, which includes the split administration browser tests ([evidence](browser-split-evidence.md)). Tested on the local Compose stack (`weavdash`) with rebuilt images, in Chromium only. No backend change.

## Change

- **Operators card:**
  - The 28px compact header, view chips with counts (All, Active, No authenticator, Revoked) and a search box for name or email.
  - The list still arrives whole from `app_platform_operators()`, so the views, search and counts apply in the browser. All stays the default, so a revoked grant stays visible, listed last, right after a revocation.
  - 50 rows are shown at a time with "Show 50 more" and a "Showing n of m operators" status, instead of rendering every grant at once.
  - Your own row, and the operator just granted, changed or revoked (or the one reached from an account page), stay at the top. The save keeps the page state, so this pin, the view and the search survive it. This came from the browser test: with 1,057 local grants sorted by name, both rows had fallen outside the first 50.
- **Rows:** the operator with email ("This is you" and the own-grant note under it for the acting owner), status (Active, Revoked or Account suspended), capabilities shortened with a tooltip, and authenticator readiness. Change capabilities, Revoke platform access and Grant again moved from inline buttons into the shared `RowActionMenu` ("Actions for …"). The acting owner's row still says they cannot change their own grant, and whether they are the last usable owner.
- **Access dialog (`features/admin/OperatorAccessDialog.vue`):**
  - The grant, change and revoke form moved from below the table into a `rec-dialog`. It keeps the same capability checklist with descriptions, the "This removes" warning, reason, password, fresh authenticator code, version and own-grant errors, and the same Cancel-first confirmations: Record, Reduce or Revoke platform access.
  - The dialog stays open behind the confirmation, so server errors such as a wrong code appear beside the fields. It closes on success and returns focus to the row menu.
  - Arriving from an account page (`?account=`) opens the grant dialog for a verified identity. An unverified identity still gets the existing explanation card.
- **Components:** reused `RowActionMenu`, `FilterChips` (button mode), `DataTable`, `StatusBadge`, `EmptyState`, `UnavailableNotice`, `DialogClose`, `containDialogFocus`, `confirmAction` and `useValidationFocus`. Added `adm-more` in `admin.css`.

## Verification

| Command | Result |
| --- | --- |
| `format:check:changed`, `typecheck`, `build` | Passed. |
| `npm test` | 38 files, 226 tests passed. New: 50 rows at a time with Show more, your own row first, and the saved operator pinned second. The operator test now covers the row menu trigger name, the No authenticator view and search, opening the dialog for Change (checked capabilities, the removal warning, then the Reduce confirmation with no request sent) and for Revoke (no checklist, Revoke confirmation). |
| Browser `administration` and `owner-mfa`, desktop and mobile | First run: 29 passed; "operator access" failed on both because the acting owner's and the peer's rows were outside the first 50 of 1,057 local grants. After pinning: 31 passed and 3 skipped (7.4 min; `owner-mfa` skips itself where owner MFA is not enforced). After moving the own-grant note: `operator access`, the module sweep and the small-screen drawer passed again on both (15 passed, 1 skipped). |

The browser test "operator access: fresh verification, explicit review, confirmed and audited revocation" now opens the row menu, chooses Revoke platform access and checks the dialog before the existing confirmation and audit checks.

## Visual inspection

Temporary captures, outside the repository and not retained: the list at 1440 and 390 px, the grant dialog opened from an account at 1440 and 390 px, and an open row menu at 1440 px.

- No page-level horizontal scroll.
- 1440: chips, search and table on one row each; the dialog scrolls internally with Cancel and Review and save fixed at the bottom.
- 390: two-line records with the status at the top right; the dialog fills the screen with its footer visible.
- Found and fixed during inspection: "You cannot change your own grant" wrapped onto three lines in the narrow Actions column. It now sits under the acting owner's name and email, and that row has no menu.

## Unverified gates

- WebKit and native Safari, manual screen reader and zoom reviews (inherited).
- Server-side paging of operators (the list still arrives whole; the page now shows 50 at a time).
- Remaining administration pages (Jobs and health, Data cleanup, Brand requests, Privacy requests) still use the earlier layout.
- Staging and production: not deployed.
