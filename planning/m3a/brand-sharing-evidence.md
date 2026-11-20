# Brand sharing evidence

**Scope (2026-11-21):** the Partner Brand sharing page (`/partner/brand-sharing`, `/professional/brand-sharing`), following the user-approved compact mockup. Branch `ui/brand-sharing` from `origin/main` at `66c2af9`. Local Compose stack (`weavdash`) with rebuilt `app`, `worker`, `scheduler` and `acceptance` images; Chromium only.

## Change

- **Layout:** 28px title with a one-line privacy reminder, a Share a lead button, and three summary tiles (connected Brands, leads shared now, withdrawn or ended). Shared leads take the main column; Connected Brands and Your shared profile cards sit beside them, and stack below 1100px.
- **Shared leads:**
  - Shared / Not shared / All chips with counts, a Brand filter (shown with two or more Brands) and a debounced search, all kept in the address.
  - Compact table: lead link with "Contact shared" or "Name only" and the summary, Brand, stage, sharing badge (Shared, Paused, Not shared) with its reason, and a short first-shared date.
  - Edit shared details and Withdraw registration as icon buttons with full accessible names; withdrawal stays confirmed.
  - Numbered pagination; compact rows on phones.
- **Connected Brands:** each Brand with its active shared-lead count and an Active or Paused badge, noting that leads are not visible while paused.
- **Your shared profile:** a read-only summary, with an Edit dialog holding the existing profile form and a note that every connected Brand sees it.
- **Share a lead dialog:**
  - Choose a lead and a Brand, using SelectField. Brands the lead is already shared with, and paused Brands, are excluded.
  - Set the name the Brand sees and an optional summary with a counter, and optionally share the contact (the dialog shows which).
  - A two-column "What the Brand will see / Never shared" note.
  - Arriving from a lead's "Register with a Brand" link (`?lead=`) opens the dialog with that lead chosen.
- **Unchanged:** registration, re-sharing, editing, withdrawal and the profile use the existing routes and database functions. Only owners and admins can share, withdraw or edit the profile.

## Backend and data contract

- `LeadRegistrations::own()` accepts `state` (`shared` = active, including paused Brands; `not_shared` = withdrawn for any reason; `all`), `brand` (partnership ID) and `q` (lead and Brand names), and returns `counts`. Counts follow the Brand and search filters. The default state is `all`, so the full history stays visible and the existing JSON callers are unaffected.
- `brands()` adds `shared` (active registrations per partnership). New `shareable()` returns up to 200 recently updated, non-lost leads with the partnerships each is already shared with, for managers only.
- The page adds the `counts`, `filters`, `summary` and `leads` props. No migration, route or permission change.

## Verification

| Command | Result |
| --- | --- |
| `acceptance` `--filter BrandLeadVisibilityTest` (one new test: filters, counts, Brand totals, shareable leads, managers only) | 10 tests, 550 assertions passed. |
| `pint --test`, `format:check:changed`, `typecheck`, `npm test`, `build` | Passed; 182 component tests (`BrandLeadVisibility.test.ts`: existing sharing test updated, one new). |
| Browser `brand-lead-visibility`, `partner-navigation`, `professional-ui`, desktop and mobile | 12 passed. The journey uses the auto-opened share dialog, the Connected Brands count, the profile Edit dialog (unsafe URL refused, focus returns to Edit) and confirmed withdrawal, with axe and touch-target checks at 1440/1920 and 390/320px. |

- **Not run here:** `PresentationDatasetTest` requires a fresh disposable database.
- **Rendering:** inspected at 1440, 1024 and 390px with shared and withdrawn leads, a saved profile and the share dialog. Temporary captures were deleted.
- **Not verified:** WebKit/Safari, staging, manual screen reader, and the full PostgreSQL suite with `rls.sql` in a disposable project.
