# Partner navigation and consolidated leads

**Confirmed D24 (2026-10-22):** partner sections have canonical URLs, legacy page bookmarks redirect, and lead management uses the portal board plus a create modal/detail drawer. See the [route contract](portal-contract.md#partner-navigation-and-lead-consolidation-follow-up).

The final local run passed all **40 Chromium/WebKit desktop/mobile journeys**, with no skips, failures or flaky tests, in 447 seconds. Coverage includes canonical/legacy URLs, avatar menu icons/navigation, modal focus/Escape, browser Back, pagination, seven stages, saved and stale drafts, snooze, activity, deletion, viewer/revocation, reusable contacts/reminders, templates, onboarding/uploads, public presence and partnership privacy. The 36 retained axe attachments report zero violations; portal journeys additionally assert clean axe results directly. Linux WebKit uses the pinned Playwright container; native Safari remains a separate release review.

Backend verification passed 26 identity tests/252 assertions, 27 PostgreSQL tests/503 assertions, and 5 focused pipeline/CRM-role and partnership-security acceptance tests/128 assertions. Frontend verification passed 21 tests, TypeScript and the production build. PHP style passed across 99 files; source secret scanning and its positive control, documentation links and diff checks passed. This follow-up has no schema migrations. No fresh-install or remote-CI claim is made.

[Structured verification](../evidence/m3a/partner-navigation/verification.json) retains source hashes, test results, the pinned browser image and scope. The historical reference-fixture screenshots are not substituted for this follow-up. The visual harness now resolves the existing `@` alias needed by the extracted lead forms.

| Screen | Desktop | Mobile |
|---|---|---|
| Partner leads | [Screenshot](../evidence/m3a/partner-navigation/leads-desktop.png) | [Screenshot](../evidence/m3a/partner-navigation/leads-mobile.png) |
| Lead detail drawer | [Screenshot](../evidence/m3a/partner-navigation/lead-drawer-desktop.png) | [Screenshot](../evidence/m3a/partner-navigation/lead-drawer-mobile.png) |

The drawer captures are full-page browser screenshots: the fixed dialog/backdrop occupy the viewport, with the rest of the underlying document retained below. Native modal focus and scrolling were exercised by the browser journeys.

Manual screen-reader, actual full-browser zoom and native Safari reviews remain pending. No production deployment, external messaging or M4 implementation was performed.

## Local-time English greeting (2026-11-17)

**Confirmed:** replace the static partner greeting with English based on the user's browser timezone. Working-tree change based on `e6c4a5a`: morning before 12:00, afternoon from 12:00 to 17:59, evening from 18:00. Refresh every minute while mounted; the real-account date also follows local midnight. Reuses `PartnerDashboard` and its existing heading styles and `PartnerChrome`; no new presentation components or data contracts. Professional leads retain their existing page title.

- `npm run format:check:changed`, `npm run typecheck`, and `npm run build`: passed.
- `npm test -- tests/Frontend/PartnershipPaused.test.ts`: 9 passed, including greeting boundaries, noon/evening/midnight updates and timer cleanup. Corrected the existing experience mock to use a Vue ref so partner branches are actually exercised.
- `node /tmp/weavdash-greeting-check.mjs`: Chromium synthetic portal checks passed. At the same instant, Manila showed evening, Los Angeles morning and London afternoon; an open page changed greeting at noon without reloading. No page errors. Heading bounds checked at widths 320, 390, 768, 1024 and 1440px (1000px height).
- Visually inspected temporary 390×1000 and 1440×1000 captures: greeting legible, aligned with existing date/actions, no clipping. Captures removed after inspection; no new images retained.

Scope: production Vue component in the existing development fixture plus real-account component tests. Authenticated app journeys, WebKit/native Safari and remote CI were not rerun for this text/clock change; inherited release gates remain open. No deployment performed.
