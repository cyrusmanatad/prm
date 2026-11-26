# Brand Partner record redesign — evidence

Date: 2026-11-26. Branch `feat/brand-partner-record` from `origin/main` at `5bb4976`. **Status:** implemented and locally verified; not committed, not merged, not deployed. Design: approved [mockup review](brand-partner-detail-mockup-review.md) (**Confirmed** 2026-11-26), with its open choices at the proposed defaults.

## Scope

| Area | Change |
| --- | --- |
| `pages/BrandPartner.vue` | Rebuilt to the approved layout: breadcrumb; one record header with avatar, `Partner record` eyebrow, the one `h1`, status badge, Partner since (date; exact time as `title`), Last active (relative · kind); `Email <contact>` (only when shared) and `Open in Lead registrations`; two compact linked figures and a scope note. Then Registered leads (count, search, stage, window, sort, table, pagination, three empty states) beside the read-only Shared profile (≥1200px) and a collapsed `What this record includes`. Paused: Paused badge, existing unavailable notice, no figures, actions, Last active, profile, filters or rows. |
| `features/brand-leads/RegistrationFilterBar.vue` (new) | Stage chips with counts, search (350 ms debounce, Enter applies), stage select and Filters button on phones, optional Partner select, Registered window, phone Sort select, `Filters applied` / Clear filters. Extracted from `BrandLeadRegistrations.vue`, which now uses it with the Partner select; the record uses it without one. |
| `components/StatCard.vue`, `foundation.css` | `compact` variant: one linked line (figure, label, scope, chevron). It keeps the rule that `null` shows `Unavailable`, never zero. |
| `BrandLeadController::partner` | Accepts `q`, `stage`, `window`, `sort` through the existing `filters()` rules (a `record` mode drops `partner` and `per_page`). It forces `partner` to the route's relationship, passes the filters to the existing `listing()` and `facets()`, and returns `filters` and stage facets. No new query, policy, RLS, grant or migration. |
| `features/portals/types.ts` | `PartnerRecordFilters`. |
| [Contract](brand-lead-visibility-contract.md#6-routes) | Partner record route parameters documented. |

**Bug fixed (pre-existing on Lead registrations).** Clear filters emptied the search box, which re-armed the search timer because it compared with the last server response. 350 ms later a second identical visit was sent, and Inertia cancelled any navigation started in between. The browser journey reproduced this deterministically on mobile (clicking a lead right after Clear filters stayed on the page). The filter bar now compares with the latest requested value. A fake-timer component test fails on the old comparison and passes on the fix.

**Deviation from the mockup.** On phones the stage filter is the production pattern, a `Stage` select plus a `Filters` button holding Registered and Sort, rather than the mockup's sideways-scrolling chips. This keeps one behaviour with Lead registrations.

Reused: `BrandChrome`, `ContentSection` (header slot), `DetailList`, `EmptyState`, `FilterChips`, `Pagination`, `PartnerAvatar`, `SelectField`, `StatusBadge`, `RegistrationTable` (existing `sort`/`sortHref`), `useListFilters`.

## Commands and results

| Command (from `src/` unless noted) | Result |
| --- | --- |
| `npm run format:check:changed` | 9 changed frontend files pass |
| `npm run typecheck` | Pass |
| `npx vitest run` | 249 passed, 4 failed. The 4 (`AccountMenu.test.ts` ×2, `PortalNavigation.test.ts` ×2) expect `Switch workspace / account security`. They fail only because of the separate uncommitted `AccountMenu.vue` wording change already in the working tree (`Workspace / Account security`), which this work did not touch. `BrandLeadVisibility.test.ts`: 11 passed |
| `npm run build` | Pass |
| Pint `--test` on the controller and acceptance test | Pass |
| Acceptance `--filter BrandLeadVisibilityTest` (PostgreSQL runtime role, RLS) | OK, 11 tests, 623 assertions. The new test covers search, stage facets, window, sort, ignored `partner`/`per_page`, 422s and foreign 404 |
| `npm run test:browser -- brand-lead-visibility.spec.ts --project=desktop --project=mobile` | 4 passed, twice consecutively on the final build. The record step now checks the header status and Last active, axe, overflow and touch targets at review widths for the record and its filtered state, the record search in the address, the no-match state, Clear filters, and opening a lead afterwards |

**Environment.** Disposable Compose project `weavdash-bpr` (scratch override outside the repository: fresh volumes, no ClamAV, array mail, owner MFA off, app on `:8031`). Migrated, `grant-runtime.php`, `rls-fixtures.sql` and `rls.sql` applied. It was removed afterwards with `down --volumes --rmi local`. The staging-derived `weavdash` database was not used. No email was sent.

## Visual inspection

Chromium, temporary screenshots outside the repository (deleted). Real data came from the browser journey, plus the production page mounted with synthetic props through a scratch Vite harness (populated, empty, no profile, paused, long content).

- **Widths:** 1440, 1024, 768, 390 and 320, Burgundy and Purple. No horizontal page overflow; the long table scrolls inside its region.
- **Fixes after inspection:**
  - Shared-profile labels now stack in the narrow side column (they wrapped values badly).
  - Header actions are full width on phones.
  - Profile links keep 44px targets but wrap as text, so the new-tab icon follows the address.
- **Lead registrations:** looks unchanged after the extraction.

## Not verified

- The original target record on the staging-derived database (login not available).
- Safari/WebKit.
- Screen-reader output beyond axe and semantic checks.
- Staging.
