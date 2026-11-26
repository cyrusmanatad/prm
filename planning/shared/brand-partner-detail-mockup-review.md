# Brand Partner detail — mockup review

Date: 2026-11-25; revised 2026-11-26 (search, filter and sort added to the leads table at the user's request). Baseline: `cce4871` plus existing local changes (untouched). Source: [handoff](brand-partner-detail-mockup-handoff.md). Status: **Confirmed (2026-11-26):** the user approved this direction, including table search, filter and sort. Open choices below default to the proposed options unless the user says otherwise. **Mockup**. Production implementation: merged to `main` at `54fb7ef` ([cyrusmanatad/prm#101](https://github.com/cyrusmanatad/prm/pull/101)) on 2026-11-26; not deployed; see [evidence](brand-partner-record-evidence.md). Production page, components, CSS, routes and data are unchanged; nothing is implemented.

## Viewing

**Mockup:** [`mockup/brand-partner-detail/index.html`](../../mockup/brand-partner-detail/index.html) with `styles.css` and `app.js` beside it. It uses the repository's licensed font subsets from `src/resources/fonts/`, so serve from the repository root:

```bash
python3 -m http.server 8765 --bind 127.0.0.1
```

Open `http://127.0.0.1:8765/mockup/brand-partner-detail/`. The dark dashed bar at the top is prototype-only: **State** (populated, no registered leads, no shared profile, paused, long content) and **Personal accent** (Burgundy default, Purple). Review links can preset them, for example `?state=paused&palette=purple`. Nothing is stored, no request leaves the page, and every product link is a demo link that reports the app address it would open in the bar instead of navigating.

## Live-page inspection outcome

The target route redirected to `/login` (HTTP 302). The connected Chrome could not reach the WSL-hosted app, and no project fixture account belongs to the Brand that owns the target partnership (checked with one read-only `SELECT` on users and memberships; no seeding, credential, MFA, appearance or data changes). **The live record was not seen.**

Instead, the production `BrandPartner.vue` page was rendered unmodified with synthetic props through a temporary Vite harness outside the repository (the real `PortalShell`, `BrandChrome`, shared components and CSS, `App` from `@inertiajs/vue3`), at 1440×900 and 390×900. The critique below describes that rendering. It reflects production markup and CSS, but not the target record's real content.

## Critique of the current page

- **Identity and context.** Name and avatar float on the cream background with the explanatory line `Partner record: what … shares with …`. Partnership status, Partner since and Last active sit in a separate card further down, so a reader cannot tell at a glance whether the partnership is active.
- **Competing figure cards.** Two full-width `StatCard`s each hold one number, a scope sentence and a link. At 1440px each is ~530px wide for a two-digit value, the largest blocks on the page carrying the least information.
- **Grouping and whitespace.** `Partnership` (3 fields) and `Shared profile` (4 fields, long description) share a two-column row with unequal heights, leaving a large empty area under Partnership. The `Last active` explanation is detached from its field at the bottom of the card. `Partner since` shows a time of day (`22:07 GMT+8`) that carries no meaning for a partnership start.
- **Primary content below the fold.** The registered-leads table, the Brand's main working list, starts at ~810px on desktop (just below a 900px viewport) and ~1,600px of 2,820px on a phone, after both figure cards and both detail cards.
- **Table.** Already good: linked lead names, stage chips, relative dates with exact titles, `—` for no update, stacked records on phones, server paging. No change needed beyond placement and a link to the full filter/sort list.
- **Next steps.** The only onward actions are two figure links and the lead links. Emailing the shared business contact is possible only from the profile card, and filtering/sorting this partner's leads is not signposted.
- **Mobile order.** Back link, title, two figure cards, Partnership, Shared profile, then leads; 2,820px tall at 390px.

## Proposed direction

One record header, then a primary leads column with a secondary shared-profile column. It keeps the WeavDash shell, cards, tokens, badges, avatar, table and pagination, and changes hierarchy and grouping only.

1. **Breadcrumb** `Partner hub › <Partner>` replaces the back link. Same target, plus orientation.
2. **Record header card.** Avatar, `Partner record` eyebrow, the one `h1`, then a meta line: status badge (Active/Paused), Partner since (date; exact time in the `time` title) and Last active (relative · kind). Actions on the right: `Email <contact>` (only when the partner shares an email; same `mailto:` the profile already offers) and the primary `Open in Lead registrations` (the existing partner-filtered list).
3. **Compact figures inside the header.** Two linked chips, `23 Leads registered · All dates and stages` and `7 In the last 30 days · Newly registered with you`, using the same targets as today. One scope note states what the counts are not: sales, revenue or the private pipeline.
4. **Registered leads (primary column).** Count beside the heading, a description that the lead opens its shared summary/contact, the existing table columns, `Showing x–y of N` and Previous/Next. Above the table, the same controls as Lead registrations, scoped to this partner (revision 2026-11-26):
   - **Stage chips** (`FilterChips`, compact) `All stages`, `Lead` … `Lost`, each with a count that ignores only the stage filter, matching the list's facet rule.
   - **Search** `Search leads`: literal lead-name match, applied 350 ms after typing stops or at once on Enter. Matches are highlighted in the results.
   - **Registered**: `Any time` / `Last 30 days`.
   - **Sort**: `Lead` and `Registered` column headers sort (`aria-sort`; first click A–Z / newest, next click reverses). On phones, where rows become cards without headers, a `Sort by` select offers the same four orders.
   - **Feedback**: a status line (`4 of 23 match "co" · Lead`) with `Clear filters`. If nothing matches, an empty state with Clear filters. Changing a filter returns to page 1.
   - Controls are hidden when the partner has no registrations or is paused, because there is nothing to filter.
5. **Shared profile (secondary column, ≥1200px; below leads otherwise).** Marked `Managed by the partner · read-only`, with no edit affordances. Fields as today, `Not shared` for missing ones, the website hostname shown with an external-link icon.
6. **`What this record includes`** disclosure, collapsed by default. It holds the sharing contract and the Last active definition that previously sat under the Partnership card.

Measured on the mockup: the table starts at ~630px on desktop (in view at 1440×900 even below the prototype bar) and ~915px on a phone, which is about 700px earlier than the current page.

### States

| State | Header | Leads | Profile |
| --- | --- | --- | --- |
| Populated | Active, dates, actions, figures | Table + paging | Fields |
| No registered leads | Figures show real `0` (partnership active, nothing shared) | Empty state naming partner and Brand | Fields |
| No shared profile | No Email action | Table | Compact empty state; no Brand action offered |
| Paused | `Paused` warning badge, paused avatar tint, Partner since only; existing unavailable notice replaces figures; no actions, no Last active | `Registered leads are not shown while paused`; no count | `Not shown while the partnership is paused.` |
| Long content | Name wraps; workspace label truncates in the account button | Long lead names wrap inside the table | Long email/URL break; multi-paragraph About keeps line breaks |

Paused never shows zero, stale figures, profile, activity or rows. Synthetic data only. Partner, workspace and lead names, `example` domains and `demo-*` IDs are invented, and no real UUID is embedded.

## Inventory and mapping

| Current item (source) | Proposed location | Note |
| --- | --- | --- |
| `BackLink` to `/brand` | Breadcrumb `Partner hub` | Same target |
| Avatar (`PartnerAvatar`, paused tint), name `h1` | Header identity | Unchanged components |
| Explanatory line `what X shares with Y` | Figures scope note + disclosure | Wording kept in spirit |
| Partnership status Active/Paused (`StatusBadge`) | Header meta | Not a pipeline stage |
| Partner since (`accepted_at`) | Header meta, date only | Exact value in `time` title |
| Last active (relative, kind, exact, `Not yet recorded`/`Paused`) | Header meta; hidden while paused | Exact time moves to title (as table cells already do); definition moves to disclosure |
| `Leads registered` → `/brand/lead-registrations?partner=` | Header figure 1 | Same link |
| `Leads (30d)` → `…&window=30d` | Header figure 2 | Same link |
| Paused notice (`wd-warning`, `role=status`) | Header, replacing figures | Same copy |
| Shared profile: contact, email (`mailto`), website (http/https only, new tab, `noopener noreferrer nofollow`), description (`pre-line`), `Not shared` | Secondary column | Same rules; Email also surfaced as header action |
| `RegistrationTable` (`showPartner=false`; Lead, Stage, Registered, Last update; lead → list drawer) | Primary column | Same columns and targets |
| `EmptyState` (paused / none yet) | Primary column | Copy extended to name partner and Brand |
| `Pagination` (`?page=`, 10 per page) | Under table | Prototype pages client-side only |
| (new) Search, stage, window, sort | Above the table | Same parameters and options as Lead registrations; needs a backend change (below) |
| `canManage` prop | Not used | Unused by the current page too |
| Shell: light Brand header, workspace name in account button, sidebar with Partner hub current and disabled Campaigns/Incentives/Enablement/Payouts, Collapse, mobile drawer | Unchanged | Reproduced for context, not redesigned |

## v2 deviations

v2's prominent record header, breadcrumb and grouped About panel informed the layout. Deliberately **not** carried over: the `Brand partner` label, the Lead → Pitched → Negotiating → Onboarded path (those are stages of a Partner's private leads; this record's status is the partnership's Active/Paused), Follow, New contact, New deal, Edit, field edit pencils, Email/Meeting/Call/Task composers and the activity feed. None is a Brand capability on a Partner-controlled record (D38), and an invented feed would misrepresent `Last active`, which is a single computed signal.

## Components

**Reused as-is:** `BrandChrome`/`PortalShell`, `PartnerAvatar`, `StatusBadge`, `StageBadge`, `RegistrationTable`, `Pagination`, `EmptyState`, `DetailList`, `ContentSection` (leads and profile cards).

**Implementation notes, if approved:**

- The record header is page-specific. Keep it in `BrandPartner.vue` unless the Partner-side Brand record adopts the same pattern; extract a `RecordHeader` only then.
- The linked figure chip is a compact variant of `StatCard`. Prefer a `compact` prop on `StatCard` (keeping the null-means-unavailable rule) over a second component.
- The breadcrumb is new markup. Check for an existing breadcrumb pattern first (admin uses `BackLink`). It may be better as a small shared component if other record pages follow.
- `ContentSection` can carry the count through its existing `header` slot.
- **Search/filter/sort reuse.** Use `FilterChips` (compact), the list page's search box and `SelectField` controls, and `RegistrationTable`'s existing `sort`/`sortHref` props (already used by Lead registrations). The search/chips/window toolbar is now repeated on two pages, so extract it from `BrandLeadRegistrations.vue` into a shared `RegistrationFilters` component (partner filter optional) rather than copying it. The mockup uses native selects only to stay standalone.
- **Backend change required for search/filter/sort.** `GET /brand/partners/{relationship}` validates only `page` today. It needs to accept `q`, `stage`, `window` and `sort` through the controller's existing `filters()` rules, with `partner` forced to the route's relationship (never taken from the query). It passes them to the existing `LeadRegistrations::listing()` and returns `filters` plus `facets()` for the chip counts. No new query, policy, RLS or migration: both methods already apply the Brand-visible rule. Header figures stay unfiltered (`leads_total`, `leads_30d`). Add feature tests for validation (422 on invalid values), a forged `partner` parameter, paused and foreign 404s, and facet counts. Otherwise every displayed value already exists in `PartnerRecord`.

## Future ideas (not in the recommended baseline)

- Stage breakdown of this partner's shared leads. The list page's `facets` already compute it, but the Partner payload does not. It would need a backend addition and must stay "shared registrations by stage", not performance.
- `End partnership` on the record, reusing the hub's icon action, `canManage` and `ConfirmationDialog`.
- A page-size control (10/25/50), as the list offers. Left out to keep the record compact.

## Verification

Chromium (Playwright `chromium-1248`, headless), served by `python3 -m http.server` from the repository root.

| Check | Result |
| --- | --- |
| Rendering at 1440×900 and 390×900, both palettes; reflow at 320/768/1024 | Inspected populated, paused, long, empty and no-profile; findings applied (mobile header meta wrap, full-width actions/figures, profile link targets, consistent synthetic counts) |
| Horizontal overflow, 5 states × 320/390/768/1024/1440 | `scrollWidth` equals viewport in all 25 |
| One `h1`, one `main` | All 25 |
| Touch targets ≥44px at 320/390 | Pass after fix. Desktop table/pagination keep production density (as `--wd-table-control`) |
| Keyboard | Order: prototype controls → skip link → account → nav → Collapse → breadcrumb → header actions → figures → stage chips → search → Registered → column sorts → leads. Chips and column sorts keep focus on the activated control after results update; typing keeps focus in search; Clear filters returns focus to search; paging moves focus to the section heading; drawer opens, closes on Escape and returns focus to Menu; Collapse updates `aria-expanded` |
| No requests or storage | 0 requests outside the local server; `localStorage`/`sessionStorage` empty |
| Contrast (WCAG) | Muted/paper 5.6, Burgundy/white 8.8, Purple/white 10.7, accent on wash 7.7/9.4, warning 6.4, success 6.3 |
| Search/filter/sort (1440, scripted) | `co` → 4 of 23 after debounce; + Lead → 2 (chip counts recomputed); + Last 30 days → 1; Clear → 23; Lead header A–Z then Z–A; Registered → newest; page 2 → 11–20; `zzz` + Enter → no-match empty state. Each step reports the app address it would load (for example `/brand/partners/demo-partnership?q=co`). No page errors |
| Filter controls at 390/320 | Sort select shown (column headers hidden), chips scroll inside their row, search/selects/chips ≥44px; no page overflow. Desktop keeps production's compact 32–36px fine-pointer density |
| Formatting | `npx prettier --config .prettierrc.json --check ../mockup/brand-partner-detail/{index.html,styles.css,app.js}` from `src/` passes (Prettier 3.9.7). The explicit `--config` is needed because files outside `src/` do not resolve `src/.prettierrc.json` |

Screenshots were temporary (scratch directory outside the repository) and deleted. Not run: production typecheck, build, PHP or browser suites (no production file changed). Not verified: the live target record, real data lengths, Safari/Firefox, and screen-reader output beyond semantic markup. No installed design skill was applied. The work followed the repository UI standards and shared components directly.

## Open choices for review

1. Header primary action: `Open in Lead registrations` (proposed) or Email as primary when a contact is shared.
2. Exact `Last active` time: title-only (proposed, consistent with the table) or kept as visible secondary text.
3. Profile position from 1024–1199px: below leads (proposed) or a two-column layout from 1024px.
4. Whether the `What this record includes` disclosure stays or the scope note alone is enough.
5. Header `In the last 30 days` figure: keep linking to Lead registrations (proposed, unchanged) or apply the in-page `Last 30 days` filter now that the record supports it.
6. Stage filter as chips with counts (proposed, as the list) or a compact `Stage` select to save vertical space; eight chips wrap to two rows in the desktop leads column.

**Confirmed (2026-11-26):** mockup approved. Implemented and merged to `main` at `54fb7ef` ([cyrusmanatad/prm#101](https://github.com/cyrusmanatad/prm/pull/101)) on 2026-11-26; see [evidence](brand-partner-record-evidence.md).
