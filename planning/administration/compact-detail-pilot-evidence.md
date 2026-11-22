# Compact administration detail pages (pilot) evidence: Account and Workspace

**Scope (2026-11-28):** the administration Account (`/admin/accounts/{id}`) and Workspace (`/admin/workspaces/{id}`) detail pages, as the pilot of a compact detail layout. The user approved the layout from a mockup rendered inside the local console. The other eight detail pages follow in a later change. Branch `ui/admin-detail-pilot` from `origin/main` at `a5782cc`. Tested on the local Compose stack (`weavdash`), in Chromium only. Frontend only: no route, controller, SQL or data-contract change.

## Change

- **Header:** the compact records header (`AdminShell compact`), with one line of facts under the title (email or workspace type, joined or created date, and a copyable ID). The status badges sit on the right: Verified/Unverified and Active/Suspended for an account; entitlement state and Active/Suspended for a workspace.
- **Summary tiles:** four `rec-stats` tiles (`adm-stats-4`), each opening the tab or page that explains it.
  - **Account:** workspaces (owned and as member), sessions on record, authenticator, and platform access. The platform access tile opens Platform access for owners and the Security tab otherwise.
  - **Workspace:** members (owner), entitlement (plan), Brand approval (Brand workspaces) or Partnerships (other workspaces), and notifications (opens the workspace's notifications where allowed).
- **Tabs:** the existing `TabNav` addresses, with counts on Workspaces and Members.
- **Fact cards:** sections are `rec-card`s. New `features/admin/FactList.vue` shows label and value pairs in two columns, one column on phones (label beside value). Help text moved next to the card title; notes and refusals use `rec-fine`.
- **Access card:** `AccessChange` and `AuthenticatorReset` now show one row per action, saying what it affects, with the button on the right (red outline for Suspend and Reset; full width on phones).
  - Each opens the shared `FormDialog` with the same server-computed impact, reason, password and fresh code, and the same version-conflict banner. Its submit button (Review suspension, Review reactivation, Review sign-out, Review reset) asks for the unchanged Cancel-first confirmation.
  - The dialog stays open on errors and closes after success. Nothing is offered when you can't act (your own account, or the last usable owner).
  - Request support access, and Record or Replace assignment, are rows of the same kind.
- **Tables:** Workspace memberships, Members, Usage, Operational summary and Activity use `rec-table` with row titles and two-line phone records.
- **Components:**
  - `FormDialog` gains `tone="danger"`.
  - `admin.css` adds `adm-meta`, `adm-stats-4`, `adm-facts`, `adm-action-row` and `adm-impact`.
  - Reused `TabNav`, `StatusBadge`, `CopyReference`, `DataTable`, `EmptyState`, `UnavailableNotice`, `ActivityList`, `UsageTable` and the confirmation dialog.

## Verification

| Command | Result |
| --- | --- |
| `format:changed`, `typecheck`, `build` | Passed. |
| `npm test` | 38 files, 230 tests passed. Updated: the suspension opens in a dialog with Review suspension; the reactivation test counts only actions outside the closed dialog. New: the authenticator reset row, dialog and destructive confirmation. |
| Browser `administration`, `owner-mfa`, desktop and mobile, shared stack | 31 passed, 3 skipped. One skip is planned; `owner-mfa` skips on both projects because the local stack does not enforce owner MFA, so the reset dialog is covered by the unit test only. Account and workspace suspension and reactivation passed through the new dialogs and confirmations, along with tab navigation with counts and the axe and overflow checks. |
| 44px sweep (temporary spec, deleted) | Account Overview (your own account, and another), the suspension dialog, Security and Workspaces tabs, and Workspace Overview, Members, Entitlements and Operations, at 1440 and 390 px: no target below 44px and no horizontal scroll. |

## Visual inspection

Temporary captures, outside the repository and not retained, of the pages above at 1440 and 390 px. The header, tiles, tabs, fact cards and action rows match the approved mockup. Phones show tiles in two columns, scrolling tabs, labels beside values and full-width action buttons. The dialog sits over the page with Cancel and the submit button in its footer. A long entitlement label ("Unassigned (development use)") wraps inside its tile.

## Unverified gates

- The authenticator reset dialog in a browser (local owner MFA is not enforced; unit-tested).
- WebKit and native Safari, manual screen reader and zoom reviews (inherited).
- The other eight administration detail pages still use the earlier layout.
- Staging and production: not deployed.
