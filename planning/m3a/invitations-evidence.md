# Brand hub invitations evidence

**Scope (2026-11-19):** compact Invitations section below the Partners table on the Brand hub, replacing the unbounded invitation history. Branch `ui/compact-invitations` from `main` at `f53b01b`. Local Compose stack with rebuilt `app` and `acceptance` images; Chromium only.

## Change

- Opens on **Pending**; chips for Accepted, Expired, Revoked and All, with counts. Five rows per status, then `Show all N`; a one-line empty state when a status has none.
- Rows show recipient, sent date, expiry (relative while pending; `tomorrow`/`today` highlighted) and a short delivery badge. The full delivery wording stays available as screen-reader text and a tooltip.
- `Refresh status` reloads only `invitations`. Revoke confirms with the shared dialog and names the recipient instead of the invitation ID.
- `PortalController` adds `recipient_email` and `created_at`, and reports a pending invitation past `expires_at` as `expired` (**Confirmed** in the [portal contract](portal-contract.md)).
- Reuse: `FilterChips` gained a button mode and a compact size; `DataTable`, `StatusBadge` and `confirmAction` are reused. The new `InvitationList` replaces the inline markup in `Portal.vue`.

## Verification

| Command | Result |
| --- | --- |
| `phpunit -c phpunit.acceptance.xml --filter 'NotificationOutboxTest\|PartnershipSecurityTest\|BrandLeadVisibilityTest\|JoinLink'` | 27 tests, 836 assertions, passed. |
| Pint on changed PHP; `format:check:changed`, `typecheck`, `npm test`, `build` | Passed; 152 component tests (4 new in `InvitationList.test.ts`). |
| Browser: `notifications`, `onboarding-notifications`, `portals`, `join-links`, desktop and mobile | Passed, including axe and document-overflow checks. |

- `NotificationOutboxTest` previously asserted that `/brand` never contained the recipient. It now asserts that the recipient is returned and that the encrypted intent and invitation token are not.
- **Rendering:** inspected at 1280 and 390 px. Phones show two-line records; long recipient addresses wrap instead of truncating; chips, Refresh and Revoke keep 44 px targets.
- **Not verified:** WebKit/Safari; staging.
