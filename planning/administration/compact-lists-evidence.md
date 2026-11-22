# Compact administration lists evidence

**Scope (2026-11-23):** the administration Workspaces (`/admin/workspaces`), Notifications (`/admin/notifications`) and Audit (`/admin/audit`) lists, following the Option A pattern the user approved for Overview and Accounts ([evidence](compact-console-evidence.md)). Branch `ui/admin-lists` from `origin/main` at `54da336`. Tested on the local Compose stack (`weavdash`) with rebuilt `app`, `migrate`, `worker`, `scheduler` and `acceptance` images, in Chromium only.

## Change

- **Shared components:** the toolbar and the applied-filters line from Accounts became `features/admin/ListToolbar.vue` and `AppliedFilters.vue`, and Accounts now uses them too. The toolbar has a search box that applies on Enter, inline dropdowns in a slot that apply as they are chosen, an optional date button and panel with Apply dates, and a phone Filters button. `AppliedFilters` shows only what the server applied, plus Clear filters and "Updating results…".
- **Workspaces:**
  - Type chips with counts: All, Creator, Brand.
  - Entitlement and Access dropdowns and a Created date panel. These combine with the chips, so a view such as "suspended Brand workspaces" is still possible. The Overview's expired-assignment link opens with the Entitlement filter applied.
  - The table shows the workspace with its type (and Professional), status, owners (shortened with a tooltip), members, entitlement with plan, and created date. Pages are numbered.
- **Notifications:**
  - Delivery-state chips with counts, with Outcome uncertain first after All. They replace the state dropdown.
  - Search; the workspace filter from a workspace page stays in the applied line.
  - Plain notification names ("Weekly review email", "Brand request approved"), short times, and the next step. Pages are numbered.
- **Audit:**
  - Outcome chips with counts: All, Completed, Denied.
  - A Source dropdown and a Dates panel.
  - Plain action names, the source under each action, and targets named like the Overview ("Account · Browser Creator"). Local pilot events keep their subject and workspace. Times are short and pages are numbered.
- **Shared format changes:** `Pagination` totals and `FilterChips` counts use thousands separators everywhere. `readableCode` and `notificationKindLabel` were added to `features/admin/format.ts`; the follow-up names match the Automation page.
- **Phones:** the shared two-line records. Each phone record hides its noisiest secondary text: the "None" next step, actor emails and plan names. All of it stays on desktop and on each record's page.

## Backend contract

Migration `2026_11_23_000001_add_platform_compact_lists` (SQL in `database/sql/platform-compact-lists.sql`):

- **`app_platform_audit_target(event, target, named)`:** a new internal helper with no runtime grant. It returns a target's kind and, when `named`, its account or workspace name. The Overview's recent activity now uses it instead of its inline copy, and the Audit list uses it for platform events.
- **`app_platform_workspaces`:** adds `facets` (all, creator, brand). The counts follow the search, dates, entitlement and access but not the type filter. Members, owners and plan are now worked out only for the 25 rows shown.
- **`app_platform_notification_list`:** adds `facets`, a count per delivery state that follows the search and workspace. The controller passes `facets` through.
- **`app_platform_audit_list`:** adds `facets` (all, recorded, denied), which follow the search, source and dates. Platform rows add `target_kind`, and `target_name` for directory readers only.
- Signatures and existing fields are unchanged. Rollback restores the earlier definitions and drops the helper.

## Verification

| Command | Result |
| --- | --- |
| `acceptance` `--filter 'PlatformConsoleTest\|PlatformGovernanceTest\|BrandApproval'` | 32 tests, 1,069 assertions passed. New: `test_list_counts_follow_their_scope_and_audit_targets_are_named_for_directory_readers`. |
| `acceptance` `--filter 'DeliveryOperationsTest\|NotificationOutboxTest\|OnboardingNotificationTest\|ScheduledNotificationTest'` | 43 tests, 962 assertions passed. |
| `acceptance` `--filter 'Delivery\|Notification'` (also selects one Brand approval test) | 47 of 48 passed. `BrandApprovalTest::test_budgets_transport_and_uncertain_outcomes_follow_the_existing_delivery_rules` found 5 pending account notices left by the other notification tests in the same run on the shared database. It passed alone and in the class run above. It does not touch the changed functions. |
| Migration rollback, then migrate | The earlier functions were restored, then reapplied; `weavdash_app` keeps EXECUTE on the four projections and has none on the helper. |
| `pint --test` (changed PHP), `format:check:changed`, `typecheck`, `build` | Passed. |
| `npm test` | 38 files, 222 tests passed. New cases cover the Workspaces type chips keeping other filters and the Access dropdown, delivery-state chips and notification names, and audit labels with outcome chips and local pilot events. |
| Browser `administration`, `delivery-operations`, `brand-approval`, `professional`, desktop and mobile | 9 of 10 passed on the shared run, including `administration` on mobile (8.5 min). `administration` on desktop timed out at 10 minutes, then passed alone after the timeout change below (10.2 min). |

Measured page loads on the rebuilt app were all under 2 seconds; the changed lists took 0.25–0.56 seconds. The `administration` journey's time comes from single-use authenticator codes waiting for fresh 30-second steps and the scans of every module at three widths. Its timeout rose from 10 to 15 minutes, and no assertion was removed. Splitting the journey is suggested as a separate task.

The browser specs were updated for the new controls. Searches apply on Enter, audit actions are matched by their plain names, the denied outcome chip is checked, and the Brand approval delivery row is matched as "Brand request approved".

## Visual inspection

Temporary captures, outside the repository and not retained, were taken at 1440, 1024, 768, 390 and 320 px for Workspaces, Notifications (all and Outcome uncertain), Audit and Accounts, plus the phone Filters and Dates panel on Audit.

- No page-level horizontal scroll at any width.
- 1440: chips, toolbar and tables on one row each; Notifications' seven chips fit on one line.
- 390 and 320: chips wrap; two-line records with the status at the top right.
- Found and fixed during inspection: the "None" next step, actor emails and long plan names crowded the phone records, and the pagination totals lacked thousands separators.

## Unverified gates

- WebKit and native Safari, manual screen reader and zoom reviews (inherited).
- Remaining administration pages (Plans, Assignments, Jobs and health, Platform access, Support access, Data cleanup, Brand requests, Privacy requests) still use the earlier layout.
- Staging and production: not deployed.
