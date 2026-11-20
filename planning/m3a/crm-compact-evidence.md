# Compact contacts and reminders evidence

**Scope (2026-11-20):** the Contacts and reminders page (`/partner/contacts`, `/professional/contacts`), following the user-approved compact mockup. Branch `ui/compact-crm` from `origin/main` at `42087df` (verified on the identical tree at `1381540`). Local Compose stack (`weavdash`) with rebuilt `app`, `worker`, `scheduler`, `migrate` and `acceptance` images; Chromium only.

## Change

- **Layout:** breadcrumb, 28px title (24px on phones; the Professional shell keeps its own heading size), lead context chip with a clear control, and Export / New reminder / New contact in the header. A four-figure workspace summary shows contacts, overdue reminders (a link to the Due filter), due in 24 hours and due in 7 days. The summary counts the whole workspace, so it no longer depends on the visible page.
- **Contacts:** `DataTable` at list density (30px controls with a fine pointer, 44px on phones and touch screens), with name/email, company, notes, a Leads column (Link / Linked inside a lead context, otherwise a lead count), an edit button and a `RowActionMenu` with Copy email, Send email, Add reminder and Delete contact… (disabled while the contact is linked). Search filters as the user types; Sort and Lead links selects apply at once. Linked contacts appear as chips you can unlink.
- **Bulk actions:** row checkboxes and select-all for the page; Link to the lead in context, Export selected and Delete… (confirmation required).
- **Reminders:** pending reminders soonest first (they were newest-created first), grouped under Overdue / Today / Upcoming / Completed with the related lead's name. Each row has a snooze menu (in 3 hours, tomorrow 9:00, next Monday 9:00, or pick a date and time), plus an Edit button.
- **Pagination:** numbered `Pagination` for both lists with totals; contacts offer 10/25/50 rows per page. Each list keeps its own page, and changing its filters returns it to page 1. Mutations return to the same pages and filters.
- **Phones and tablets (<1100px):** a Contacts / Reminders switch shows one list at a time. The choice persists through paging and Back/Forward for the browser session. Contact rows collapse to one line plus the company.
- **Editor dialog:** paired name/email fields; footer with Delete (when editing), Cancel and Save. The Reminder/Contact switch appears only when creating a record.
- **New shared pieces:** `RowActionMenu` (reka-ui dropdown on the account-menu surface, with `.wd-row-menu` styles in `foundation.css`); `features/crm/snooze.ts`; `features/crm/panel.ts`; and `App\Support\CsvDownload`, now also used by the Professional export.

## Backend and data contract

- `GET /partner/contacts` (and `/crm` JSON) accepts `sort` (`name_asc`, `name_desc`, `company_asc`; default most recent), `linked` (`linked`, `unlinked`), `per_page` (10/25/50), `contacts_page` and `reminders_page`. Out-of-range pages clamp to the last page. Each list returns `{items, page, perPage, total}`. Contacts add `lead_count`; reminders add `lead_name`. A new `summary` object is returned. **Contract change:** `contacts_cursor`, `reminders_cursor` and `nextCursor` are removed.
- `POST /contacts/bulk` (`action` `delete` or `link`, 1–100 `{id, version}` items, plus `link_entry`/`entry_version` for link; throttled 30/min). All versions must match and nothing changes unless every contact can change. Delete is refused (409) while any selected contact is linked to a lead. Each contact is audited.
- `GET /crm/exports/{contacts|reminders}` returns CSV of the current filters, or of up to 100 selected contact `ids[]`. It is open to anyone who can read the workspace CRM, including viewers (who can already read the same records on the page), throttled 10/min, audited as `contacts.exported` / `reminders.exported`, `private, no-store`, with spreadsheet-formula cells neutralized. **Assumption / Open:** viewer access to export follows the existing Professional export, which has no role restriction; confirm if exports should be limited to writers.
- No schema change. RLS is unchanged; every new query runs inside the request's tenant context.

## Verification

| Command | Result |
| --- | --- |
| `phpunit -c phpunit.pgsql.xml --filter CrmTest` (two new tests: paging/sort/link filter/summary; bulk all-or-nothing and export safety) | 5 tests, 120 assertions passed. |
| `acceptance` `phpunit.acceptance.xml --filter 'CrmRoleTest\|ProfessionalHubTest'` (viewer bulk forbidden, viewer export allowed, revoked export forbidden) | 14 tests, 235 assertions passed. |
| `php artisan test --compact` (default suite, in the app image) | 53 tests, 589 assertions passed. |
| `pint --test`, `format:check:changed`, `typecheck`, `npm test`, `build` | Passed; 163 component tests (`Crm.test.ts` rewritten: 7 tests). |
| Browser `crm.spec.ts`, desktop and mobile (new journey: bulk export/delete, row menu, quick snooze, CSV download, axe) | 6 passed. |
| Browser `professional-ui`, `professional`, `ui-refinement`, `partner-navigation`, desktop and mobile | Passed after reruns; see below. |

- **Local database:** before the integration run, the local database was missing the privacy migrations from PR #57 (`2026_11_20_000001/2`). They were applied through the `migrate` service and runtime grants were reapplied. The full PostgreSQL suite and `rls.sql` were not rerun in a disposable project.
- **Related failures:** `partner-navigation` expected 6 account-menu icons. Commit `64b1a6f` (merged in PR #57) added Privacy exports without updating the test; it now expects 7. `ui-refinement` (mobile) failed once before reaching the CRM page and passed on rerun.
- **Rendering:** inspected at 1440, 1024 and 390px with lead context, selection, the row menu and the snooze menu open. Contact rows are about 43px at desktop widths, and phones keep 44px targets. Temporary captures were deleted.
- **Not verified:** WebKit/Safari; staging; manual screen reader. Name sorting is plain alphabetical, so "Contact 10" sorts before "Contact 2".
