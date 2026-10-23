# Partner navigation and consolidated leads

**Confirmed D24 (2026-10-22):** partner sections have canonical URLs, legacy page bookmarks redirect, and lead management uses the portal board plus a create modal/detail drawer. See the [route contract](../../../m3a-portal-contract.md#partner-navigation-and-lead-consolidation-follow-up).

The final local run passed all **40 Chromium/WebKit desktop/mobile journeys**, with no skips, failures or flaky tests, in 447 seconds. Coverage includes canonical/legacy URLs, avatar menu icons/navigation, modal focus/Escape, browser Back, pagination, seven stages, saved and stale drafts, snooze, activity, deletion, viewer/revocation, reusable contacts/reminders, templates, onboarding/uploads, public presence and partnership privacy. The 36 retained axe attachments report zero violations; portal journeys additionally assert clean axe results directly. Linux WebKit uses the pinned Playwright container; native Safari remains a separate release review.

Backend verification passed 26 identity tests/252 assertions, 27 PostgreSQL tests/503 assertions, and 5 focused pipeline/CRM-role and partnership-security acceptance tests/128 assertions. Frontend verification passed 21 tests, TypeScript and the production build. PHP style passed across 99 files; source secret scanning and its positive control, documentation links and diff checks passed. This follow-up has no schema migrations. No fresh-install or remote-CI claim is made.

[Structured verification](verification.json) retains source hashes, test results, the pinned browser image and scope. The historical reference-fixture screenshots are not substituted for this follow-up. The visual harness now resolves the existing `@` alias needed by the extracted lead forms.

| Screen | Desktop | Mobile |
|---|---|---|
| Partner leads | [Screenshot](leads-desktop.png) | [Screenshot](leads-mobile.png) |
| Lead detail drawer | [Screenshot](lead-drawer-desktop.png) | [Screenshot](lead-drawer-mobile.png) |

The drawer captures are full-page browser screenshots: the fixed dialog/backdrop occupy the viewport, with the rest of the underlying document retained below. Native modal focus and scrolling were exercised by the browser journeys.

Manual screen-reader, actual full-browser zoom and native Safari reviews remain pending. No production deployment, external messaging or M4 implementation was performed.
