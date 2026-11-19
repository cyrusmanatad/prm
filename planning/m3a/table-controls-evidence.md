# Table controls and partner avatars evidence

**Scope (2026-11-20):** compact table and list-toolbar controls and partner initials avatars, as recorded in the [portal UI alignment](ui-alignment.md). Branch `ui/compact-controls-avatars` from `main` at `15c2ece`. Local Compose stack with a rebuilt `app` image; Chromium only.

## Change

- `--wd-table-control` is 36px with a fine pointer and 44px below 600px or with a coarse pointer. Pagination (13 lists), the rows-per-page select, standard filter chips, the Invitations Refresh/Revoke controls and the Partners search, Search button, End partnership button and Leads (30d) link use it through `wd-table-button`, `wd-table-icon-button`, `wd-table-input` and `wd-cell-link`.
- The administration console keeps 44px at every width, as its design handoff requires.
- `PartnerAvatar` (decorative, `aria-hidden`) appears in the Partner hub table (36px), the Partner record header (48px) and the Registered leads Partner column (28px). The tint follows the partnership ID; paused partners are neutral. Contrast was computed for every text/tint pair; the lowest is 5.1:1.
- One `initials` helper replaces the copies in `AccountMenu` and `Crm`.
- The Partners card is now a named region (its heading), which also gives screen-reader users a landmark.

## Verification

| Command | Result |
| --- | --- |
| `format:check:changed`, `typecheck`, `npm test`, `build` | Passed; 155 component tests (3 new in `PartnerAvatar.test.ts`, avatar checks added to `BrandLeadVisibility.test.ts`). |
| Full browser suite, desktop and mobile | 88 passed, 6 failed, 2 skipped on the first run; see below. |
| Rerun of `brand-approval`, `brand-lead-visibility`, `delivery-operations`, `notifications`, desktop and mobile | 14 passed. |

First-run failures and resolution:

- `brand-approval` (desktop): admin pagination was 36px, against the administration 44px rule. Fixed by keeping 44px in `.wd-admin`.
- `brand-lead-visibility` (both): `All 13` also matched the Invitations chip added in PR #52, so this test was already failing on `main`. The assertion is now scoped to the Partners region.
- `delivery-operations` (both): the test used a field label changed in `56bdfb5`, so it was already failing on `main`. Label updated.
- `notifications` (mobile): the running worker delivered the invitation email before the revoke. Passed on both runs otherwise; treated as a timing race in the test environment, not a product defect.

- **Rendering:** Partner hub, Registered leads and Partner record inspected at 1440 and 390px; avatars align with names, compact controls align in the search row and pagination, and phones keep 44px targets (touch-target checks passed on mobile). Temporary captures were deleted.
- **Not verified:** WebKit/Safari; staging.
