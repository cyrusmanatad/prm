# Brand lead registrations evidence

**Scope (2026-11-21):** the Brand's Lead registrations list (`/brand/lead-registrations`) and its registration detail, following the user-approved Option B mockup (the detail opens as a side drawer over the list). Branch `ui/lead-registrations` from `origin/main` at `6bf042e`. Local Compose stack (`weavdash`) with rebuilt `app`, `worker`, `scheduler` and `acceptance` images; Chromium only.

## Change

- **Header:** 28px title with a one-line privacy note, and four summary tiles: registrations, last 30 days, partners registering, updated this week.
- **Registered leads card:**
  - A count pill, and stage chips with counts.
  - Search with an icon (debounced, kept in the address), plus Partner and Registered dropdowns at compact height.
  - A compact table: the lead shows its summary line, and each date appears once ("3 days ago", the exact time in the title). An empty last update reads "—" (with "No updates yet" for screen readers).
  - Numbered pagination with rows per page.
- **Stage chip:** stages now use the same coloured-dot chip as the partner pipeline and lead drawer (stage name as text, dot as an extra cue), replacing the separate icon-and-colour badge. This also applies on the Partner record. The unused `.wd-stage-badge` styles were removed.
- **Drawer:** a lead opens in a read-only side drawer over the list. The address keeps the filters, page and `?registration=…`, so Back, Forward and reload work; Escape or Close returns to the same list. The drawer has:
  - the stage and when the lead was registered;
  - a partner card (partner since, leads registered, **Open partner**);
  - the summary;
  - the business contact, said once ("Not shared by the partner" or "Not provided");
  - a timeline ("No changes since registering" instead of repeating the time);
  - the read-only note.
- **Old detail address:** `/brand/lead-registrations/{id}` now redirects pages to the list with the drawer open. From a Partner record it opens over that partner's registrations. JSON callers still receive the record. The old `BrandLeadRegistration` page was removed.
- **Phone:**
  - Stage becomes one select ("All stages (3)"), with Partner, Registered and Sort behind a **Filters** button.
  - Rows are two-line records: lead and summary with the stage at the top right, then partner, registered date and any update.
  - The drawer is full width.
  - The Brand header hides the four unavailable placeholder links below 640px, so it no longer wraps to three rows.
  - 44px targets throughout.
- **Reused/added:** the new `RegistrationDrawer`, with `DialogClose`, `containDialogFocus`, `PartnerAvatar`, `FilterChips`, `SelectField`, `Pagination`, `EmptyState` and `useListFilters` reused. The phone record pattern follows the invitations table.

## Backend and data contract

- **`LeadRegistrations::listing` rows:** add `summary`, the partner-written text already visible in the detail.
- **New `LeadRegistrations::summary`:** `total`, `recent` (30 days), `partners` and `updatedWeek` (shared details changed in the last 7 days), over active registrations shared with this Brand.
- **`LeadRegistrations::detail`:** adds `partner_since` (the partnership's acceptance) and `partner_leads` (this partner's visible registrations).
- **`BrandLeadController::index`:** accepts `registration` (a UUID) and returns `summary` and `selected`. Hidden, withdrawn, ended, paused and foreign registrations answer 404, as the detail route already did.
- **`show`:** returns JSON as before; pages redirect.
- No migration, route or permission change.

## Verification

| Command | Result |
| --- | --- |
| `acceptance` `--filter BrandLeadVisibilityTest` | 10 tests, 586 assertions passed. New checks: the row allowlist (now with `summary`), summary figures, `selected` with partner facts and no private fields, the redirects from the old address (including from a Partner record), 404 for unknown or another Brand's registration, 422 for a malformed one, and a zero summary for another Brand. |
| `pint --test`, `format:check:changed` (11 files), `typecheck`, `npm test`, `build` | Passed; 198 component tests. `BrandLeadVisibility.test.ts`: the list test was updated (copy, count pill, drawer links), the detail-page test became a drawer test (tiles, partner card, single contact line, timeline wording, focus and close back to the list), and the Partner record links into the drawer. |
| Browser `brand-lead-visibility`, `portals`, `notifications`, desktop and mobile | 10 of 12 passed on the first run. `brand-lead-visibility` (mobile) flagged the 42px search input; the input now carries the full control height. `notifications` (mobile) failed because the scheduler's minute-by-minute `notifications:dispatch` delivered an invitation before the test revoked it; it passed 2 of 2 on rerun, and this branch does not touch invitations. |
| Final run of `brand-lead-visibility`, `portals`, `join-links`, desktop and mobile | 14 passed. |

- **Spec updates:** `brand-lead-visibility` now opens Filters on phones, checks the Stage select there, and opens the lead in the drawer. It checks the address, focus on Close, the single Close button, and Close and Back returning to the same list. It follows Open partner, and opens the drawer from the Partner record.
- **Rendering:** inspected at 1440 × 900, 1024 × 768 and 390 × 844, plus the drawer at all three, the phone Filters panel, and the Partner hub header on a phone. No horizontal overflow. Fixed during inspection:
  - uneven dropdown heights;
  - tall labelled phone records;
  - a bold summary line.

  Temporary captures were deleted.
- **Not verified:** WebKit/Safari, staging, a manual screen reader, and the full PostgreSQL suite with `rls.sql`.
