# Workspaces evidence

**Scope (2026-11-21):** the shared Workspaces page (`/workspaces`), opened from the account menu as "Switch workspace / account security", following the user-approved Option B mockup (tabs and workspace cards). Branch `ui/workspaces` from `origin/main` at `1141968`. Local Compose stack (`weavdash`) with rebuilt `app`, `worker`, `scheduler` and `acceptance` images; Chromium only.

## Change

- **Header:** 28px title (24px on phones), a one-line description and a New workspace button. The page keeps the plain `FoundationShell` header.
- **Tabs:** Workspaces (with a count) and Account security, using the shared `Tabs` component.
  - `Tabs` gains an optional warning badge. Account security shows "Action needed" while the account owns a workspace but has no authenticator.
  - The security tab is kept in the address as `?tab=security`. A rejected password switches to it before focus moves to the field.
  - Tabs keep 44px targets at every width on this page.
- **Workspace cards:** three per row, two below 1100px and one on phones. Each card shows the initial, name, type (Partner, Professional or Brand) and role.
  - The current workspace is outlined, marked Current, and keeps the existing "Open partner portal / Professional Hub / brand hub" link.
  - Other workspaces have a Switch button named "Switch to <name>, <type> workspace".
- **Current workspace panel:**
  - Type, role and a Rename dialog (owners and admins; other roles see the existing explanation).
  - The Brand workspace requests link.
  - Tool links in a two-column grid with one-line descriptions: Automation preferences for every role; finance records, pilot entitlements and support access requests for owners, labelled "Owner".
- **New workspace dialog:** Partner, Professional and Brand as radio cards plus the name with a counter. Brand swaps the name for the existing review note and "Request Brand workspace" link. With no workspaces, the same form is shown on the page as "Create your first workspace".
- **Account security tab:** four cards.
  - **Authenticator and recovery codes:** shows Set up, Not set up (required) or Optional, with Set up or Manage authenticator.
  - **Sign out all sessions:** keeps the current password and the existing danger confirmation.
  - **Download your data** and **Account and workspace closure:** each opens from anywhere on its card.
- **Dialogs:** native `<dialog>` with `containDialogFocus` and `DialogClose`. Focus starts on the chosen type or the name, and returns to the trigger on close. Rename closes after a successful save; create closes after success.
- **Reused/extracted:** new `WorkspaceCreateForm` shared by the page and the dialog. `AccountSecurity` became the security tab. `Tabs`, `StatusBadge`, `FormField`, `DialogClose` and `wd-table-button` are reused.

## Backend and data contract

- `WorkspaceController::index` adds `security: { enrolled, required }` from the existing `app_account_mfa_state()`, inside the tenant context. `required` is true when the account owns a workspace or is enrolled. No secret, recovery count or operator flag is sent.
- No migration, route, permission or action change. Create, select, rename and revoke use the same endpoints and error bags.

## Verification

| Command | Result |
| --- | --- |
| `app` `phpunit.pgsql.xml --filter 'InertiaWorkspaceTest\|RoleAuthorization\|Workspace'` | 8 passed, including the new security status test. `PortalTest` errored for a known reason: it needs a disposable database. |
| `pint --test`, `format:check:changed` (20 files), `typecheck`, `npm test`, `build` | Passed; 184 component tests (`WorkspaceScreen.test.ts` rewritten with 8 tests; `BrandRequests.test.ts` updated for the radio types). |
| Browser `foundation`, `accessibility`, `adversarial`, `flash-feedback`, `ui-refinement`, `owner-mfa`, `confirmation-errors`, `join-links`, `brand-approval`, `onboarding-notifications`, `onboarding-lifecycle`, `automation`, `follow-up-drafts`, `scheduled-delivery`, `finance`, desktop and mobile | First run: 30 passed, 10 failed, 2 skipped. The failures came from the new markup: security card heading order, 38px tabs under a spec that checks touch targets at every width, `?tab=security` in the address, a renamed-region locator, and specs locating the old workspace button. All were fixed. |
| Rerun of `accessibility`, `brand-approval`, `confirmation-errors`, `foundation`, `join-links`, `pipeline`, `onboarding`, `ui-refinement`, desktop and mobile | 24 passed and `join-links` failed: that identity already owns a workspace, so the spec now opens the dialog. Then `join-links` passed 4 of 4. |

- **Spec updates:** 11 browser specs follow the new structure. They open the tab or dialog, check the radio types, use the Rename dialog, accept `?tab=security`, and treat a visible Switch button as "not current". `ui-refinement` now checks arrow keys through the types and that Escape returns focus to New workspace.
- **Rendering:** inspected at 1440, 1024 and 390px: empty state, three workspaces with a long name, New workspace and Rename dialogs, and the security tab with a required authenticator. No horizontal overflow. Phone badges stay on one line. Temporary captures were deleted.
- **Not run here:** `privacy-exports` and `privacy-closure` need `PRIVACY_TEST_COMPOSE` / `CLOSURE_TEST_COMPOSE`. `privacy-exports` now opens the Account security tab, but that change has not been run; `privacy-closure` needed no change.
- **Not verified:** WebKit/Safari, staging, manual screen reader, and the full PostgreSQL suite with `rls.sql` in a disposable project.
