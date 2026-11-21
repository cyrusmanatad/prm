# Account menu consistency evidence

**Scope (2026-11-21):** the shared account menu and the Partner header across pages. Branch `ui/account-menu-consistency` from `origin/main` at `6b73869`. Local Compose stack (`weavdash`) with rebuilt `app`, `worker`, `scheduler` and `acceptance` images; Chromium only.

## Problem

Checked on the running app as a Partner owner:

- **Pages with the Partner header** (My leads, Media kit and Brand sharing): the account menu listed Contacts and reminders, Outreach templates, Onboarding requests and Brand sharing.
- **Contacts, Outreach templates and Onboarding requests:** these used the plain `FoundationShell` header, so the four tool links and the My leads / Media kit navigation were missing.
- **Workspaces, Automation, Finance and Privacy exports:** the tool links were also missing.
- **Platform administration link:** platform staff never saw it on workspace pages. Both shared menu reads opened a second tenant context inside the workspace page's own transaction; the call threw, was caught and returned nothing.

## Change

- **Account menu:** derives the Partner tools from a new shared `activeWorkspace` value (`kind`, `experience`), so every page shows the same items while a Partner workspace is selected. Professional workspaces keep their own navigation menu; Brand workspaces and pages with no selection show no workspace tools. A closing account still sees only closure and security items. `PartnerChrome` passes links only for design fixtures.
- **Partner header:** `FoundationShell` renders `PartnerChrome` (no main tab current) on `/partner/contacts`, `/partner/outreach-templates` and `/partner/onboarding` while a Partner workspace is selected, as it already does with `ProfessionalShell` for Professional tools. Page content and its `main` landmark are unchanged.
- **Shared reads:** `HandleInertiaRequests` runs `platformAccess` and `activeWorkspace` as the signed-in account.
  - On workspace pages, the reads join the already-open tenant context inside a savepoint, after checking it belongs to the same account.
  - Elsewhere, they open their own context.
  - `activeWorkspace` is null without a current membership. Routes still authorize themselves; neither value grants access.

## Verification

| Command | Result |
| --- | --- |
| `app` `phpunit.pgsql.xml --filter 'InertiaWorkspaceTest\|CrmTest\|PipelineTest'` | 17 tests, 370 assertions passed. New: the selected workspace is described on `/workspaces`, `/partner/contacts` and `/professional/contacts`, and is null for a workspace the account does not belong to. |
| `acceptance` `--filter PlatformConsoleTest` | 9 tests, 355 assertions passed. Now also checks that the administration hint and selected workspace resolve on `/partner/contacts`. |
| `pint --test`, `format:check:changed`, `typecheck`, `npm test`, `build` | Passed; 186 component tests (new `PartnerShell.test.ts`; `AccountMenu.test.ts` covers Partner, Professional, Brand, no selection and closing). |
| Browser `partner-navigation`, `crm`, `outreach-templates`, `onboarding`, `onboarding-lifecycle`, `onboarding-notifications`, `pipeline`, `logged-reply`, `professional-ui`, `accessibility`, `ui-refinement`, `brand-lead-visibility`, `administration`, desktop and mobile | 42 passed. `partner-navigation` now opens each tool from the previous one, checks the seven menu items and the My leads / Media kit header on each, and checks the same items on `/workspaces` and `/automation`. |

- **Rendering:** Contacts, Outreach templates, Onboarding requests and Brand sharing were inspected at 1440, 1024 and 390px, along with the menu on `/workspaces`. No horizontal overflow; page content is unchanged under the Partner header. Temporary captures were deleted.
- **Not changed:** Automation, Finance and account pages keep the plain header; only their menu items change. Contacts content starts at the `wd-main` width (1360px) while Brand sharing uses 1280px, as before.
- **Not verified:** WebKit/Safari, staging, manual screen reader, the full PostgreSQL suite with `rls.sql`, and `privacy-*` specs (they need the separate privacy stacks).

## Brand follow-up (2026-11-21)

**Scope:** the same consistency for Brand workspaces, checked on the running app as a Brand owner.

- **Before:** the account menu showed only Switch workspace / account security, Privacy exports and Sign out on every page. A Brand owner on Automation, Finance, Entitlements, Support access or Privacy exports (all plain header) had no direct way back to the Brand pages.
- **Change:** while a Brand workspace is selected, the menu adds **Partner hub** (`/brand`) and **Lead registrations** (`/brand/lead-registrations`) on every page. Both are available to every Brand role.
- **Header:** all Brand pages (hub, partner detail, lead registrations and detail) already use the Brand header; no change was needed. Shared workspace pages keep the plain header, as for Partner.
- **Administration link:** the shared-read fix above already covers Brand pages.

| Command | Result |
| --- | --- |
| `npm test` | `AccountMenu.test.ts`: Brand items on `/brand`, `/workspaces` and `/automation`; none for Professional or no selection. |
| Browser `pipeline`, `portals`, `partner-navigation`, `join-links`, `notifications`, `administration`, desktop and mobile | 20 passed. `portals` now opens the menu on `/workspaces` and `/automation` as a Brand owner, checks the five items and returns to the Partner hub from the menu. |

- **Rendering:** no layout change; the menu reuses its existing item markup and Lucide icons. The items were checked through the browser assertions above, not a separate visual pass.

