# Professional Hub UI evidence

**Scope (2026-11-21):** the Professional Hub (`/professional`) and the `ProfessionalShell` header. The design is the user-approved "Option A + Up next" mockup: tools with live counts, plus a short Up next queue. Branch `ui/professional-hub` from `origin/main` at `82fdc1d`. Tested on the local Compose stack (`weavdash`) with rebuilt `app`, `worker`, `scheduler` and `acceptance` images, in Chromium only.

## Change

- **Header:** a 28px title (24px on phones) with the workspace name and a one-line subtitle. **Add client lead** is the brand-filled `wd-table-button`. It opens Client leads at `?create=1`, which already opens the existing Add lead dialog. It appears only when the workspace can write.
- **Summary tiles:** four linked tiles.
  - **Open client leads:** every lead except Lost, with a coloured stage bar and per-stage counts.
  - **Reminders due:** overdue plus the next 24 hours, with overdue in red and a this-week count.
  - **Onboarding to review:** submitted requests, plus those waiting on client corrections.
  - **Invoice drafts:** owner only, with drafts due this week. Members see three tiles.
- **Up next:** overdue reminders first, then onboarding submissions awaiting review, then reminders due within 24 hours. It shows up to 6 rows, with All / Reminders / Onboarding chips (`FilterChips` in button mode), "Showing n of total" and a link to the full list. Rows open Contacts filtered to the reminder, or the onboarding request. When nothing is due it shows `EmptyState`.
- **Tools:** three compact columns (Client work; Outreach and presence; Finance) built from `professionalNavigation`. Count pills show:
  - lead and template counts;
  - overdue reminders, in red;
  - onboarding and follow-up drafts to review;
  - invoice and payment counts, for the owner only.

  Profile shows a Published / Not published `StatusBadge`. For members, Finance reads "available only to the workspace owner".
- **Subscription:** a compact card with a status `StatusBadge`, the plan name, start and end in UTC, the paused or expired message, and usage bars. Bars turn amber at 80% or more; at the limit they turn red and read "limit reached". The separate subscription notice above the title is hidden on the Hub only, since the card shows it; other tools keep it.
- **Exports:** CSV buttons with download icons. Finance exports are shown to the owner only, and the accessible names are unchanged ("Export leads as CSV"). A footer links to Workspaces and account security.
- **Layout:**
  - **1440:** two columns, with the queue and tools beside the subscription and exports.
  - **1024:** stacked, with subscription and exports side by side.
  - **Phones:** one column, 2×2 tiles, two-line queue rows and 44px targets.
- **Shell:**
  - **Brand sharing** is added to the Client work menu. Its route existed but was missing from the Professional navigation; it now renders inside `ProfessionalShell`.
  - From 360px wide, phones keep the brand, Menu and account on one row. Menu becomes a 44px icon button whose "Menu" text stays for assistive technology. At 320px the header still wraps, as before.
- **Removed:** the old Hub-only styles in `professional.css` (`pro-hub-grid`, `pro-tool-*`, `pro-plan*`, `pro-usage`, `pro-exports`), which nothing else used.

## Backend contract

- **New `App\Services\ProfessionalHub`:** read-only queries inside the request's tenant context.
  - `summary(org, actor, owner)` returns `leads` (`open` and `stages`), `reminders` (reuses `Crm::summary`), `onboarding` (`submitted`, `correction`), `invoices`, `payments`, `templates`, `followUps` and `profilePublished`.
  - `invoices` and `payments` are **null for non-owners**. Row security already limits them, and null hides the tile instead of showing a misleading zero.
  - `followUps` counts the actor's own drafts only, matching the actor-scoped policy.
  - `upNext(org)` returns totals plus up to 6 reminders and 6 submitted onboarding requests. Each row has only id, title or label, due or submitted time, and the linked lead's name.
- **`ProfessionalController::index`** adds `isOwner`, `canWrite`, `summary` and `upNext` to the existing payload. `canWrite` requires an active subscription and a non-viewer role.
- No migration, route or permission change.

## Verification

| Command | Result |
| --- | --- |
| `acceptance` `--filter ProfessionalHubTest` | 14 tests, 239 assertions passed. The new `test_hub_summary_and_up_next_follow_roles_and_tenant_scope` checks stage counts excluding Lost, reminder overdue/24-hour/week counts (completed ones excluded), onboarding statuses, owner invoice figures, actor-scoped follow-ups, Up next order and lead names, and that another tenant's reminder is excluded. It also checks member (null finance, own drafts), viewer (`canWrite` false), pending and expired (`canWrite` false, figures still readable). |
| `app` `--filter InertiaWorkspaceTest` (pgsql) | 4 tests passed. |
| `pint --test` | Passed, 276 files. |
| `format:check:changed` (7 files), `typecheck`, `build` | Passed. |
| `npm test` | 36 files, 203 tests passed. The new `ProfessionalHub.test.ts` covers tile links and wording, Up next order, links and chip filtering, count pills and usage levels, member and expired states, and the empty queue. `ProfessionalShell.test.ts` now expects 10 tools including Brand sharing. |
| Browser `professional-ui`, `professional`, `partner-navigation`, desktop and mobile | 12 passed. The new Hub journey seeds a lead and an overdue reminder, then checks the title size, the hidden notice, the tiles, the queue and its chips, the tools, axe, overflow at 1440/1024/390, phone touch targets, and Add client lead opening the dialog. The presentation journey checks the 10-item menu and the one-row header from 360 to 1023px. |

The first browser run found two problems, both fixed before the final run. The workspace-settings footer link was 33px wide on phones; it is now a full-text link. The new header assertion was too strict at tablet widths; it now checks that Menu shares the brand's row.

## Rendering

- **Inspected:** 1440 × 900, 1024 × 900, 390 × 900 and the 320px header with populated synthetic data, plus Brand sharing at 390.
- **Overflow:** none at any width.
- **Fixed during inspection:**
  - At 1024, subscription and exports stretched full width; they now sit side by side.
  - The "CSV" note wrapped onto its own line on phones.
- **Fixture data:** the browser fixture names plans with a UUID, so the plan name shows as a UUID. Real plans have administrator-entered names.
- Temporary captures and specs were deleted.

## Not verified

WebKit/Safari, staging, a manual screen reader, and the full PostgreSQL suite with `rls.sql`. Onboarding queue rows were checked in component and acceptance tests, not in the browser.
