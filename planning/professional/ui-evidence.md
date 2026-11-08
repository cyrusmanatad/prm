# Professional UI evidence

Date: 2026-11-08. Source: `986c083` plus the existing working tree and this refinement. [Handoff](ui-handoff.md).

## Client leads panel restoration

**Confirmed (2026-11-08):** intentionally restore the live Partner action and sidebar panels previously removed in Professional. Today's next best actions sits immediately above the pipeline; due reminders opens `/professional/contacts?state=due`. Autopilot always precedes From your brand partners in the right column, including the empty state. Six existing automation controls remain disabled with honest unavailable copy. The standalone Summary section is hidden only in Professional; the board retains its own value/counts/filters/actions. No API, schema, dependencies, recommendations or automation were added. Partner composition, shared permissions/confirmation behavior and the Professional header correction remain intact.

The dashboard reuses its existing action section, disabled automation list, relationship records/empty state, flexible columns and `LeadManager`/`PipelineBoard`; no new presentation component or styling system was needed. The dark partnership heading explicitly inherits its card foreground, preserving readability under the Professional shell as well as Partner.

The existing presentation test now checks restored headings, all six disabled/unpressed automation controls, absence of the Summary region and actual navigation to the due-reminder destination. A new focused browser journey creates a real synthetic brand invitation, accepts it in Professional, verifies the resulting partnership, tests all five viewport widths, then exercises Cancel/Escape/focus restoration and confirmed termination back to the empty state. Menu/account access and bounds, axe, horizontal overflow and panel order/placement are checked. The layout stays beside the board at 1440px and stacks below it at 320/390/768/1024px, matching the existing flex-basis rules.

Screenshots: empty [desktop](../evidence/professional-ui/panels-empty-desktop.png) / [mobile](../evidence/professional-ui/panels-empty-mobile.png); populated [320px](../evidence/professional-ui/panels-desktop-320.png), [390px](../evidence/professional-ui/panels-desktop-390.png), [768px](../evidence/professional-ui/panels-desktop-768.png), [1024px](../evidence/professional-ui/panels-desktop-1024.png), [1440px](../evidence/professional-ui/panels-desktop-1440.png), and [mobile device at 390px](../evidence/professional-ui/panels-mobile-390.png). The final presentation run also refreshes the nine desktop/mobile after captures, including Client leads above. These supersede earlier screenshots of the removed panels.

| Command (from `src/` unless stated) | Restoration result |
| --- | --- |
| `npm run format -- resources/js/features/portals/PartnerDashboard.vue tests/Browser/professional-ui.spec.ts tests/Browser/professional.spec.ts` | Passed, pinned Prettier |
| `npm run format:check:changed` | [Passed](../evidence/professional-ui/panels-format.txt); all supported changed frontend files |
| `npm run typecheck` | [Passed](../evidence/professional-ui/panels-types.txt) |
| `npm run build` | [Passed](../evidence/professional-ui/panels-build.txt), after the dark-card heading correction |
| `npm test` | Passed; 17 files, 75 tests, including Professional shell/navigation and pipeline checks |
| `docker compose --env-file src/.env -p weavdash-professional exec -T app vendor/bin/pint --test tests/Browser/professional-fixture.php` (repository root) | Passed; 1 file |
| `git diff --check` (repository root) and planning/evidence relative-link validation | Passed |

The existing Professional administration journey now passes a unique workspace name to its disposable CLI fixture and searches that exact name. Repeated synthetic workspaces called Independent consulting had filled the directory's 25-result page, leaving the new record outside the assignment selector. Only browser-fixture setup changed; directory pagination, backend contracts and application permissions are untouched. The new panel test measures the pipeline container even when it is empty and follows the existing post-termination redirect to the Professional Hub before reopening Client leads to verify the empty state.

Restoration browser runs use `COMPOSE_PROJECT_NAME=weavdash-professional`, desktop/mobile Chromium projects and localhost:8000. The broad command was `npx playwright test professional-ui.spec.ts professional.spec.ts partner-navigation.spec.ts portals.spec.ts --project=desktop --project=mobile --output=/tmp/professional-panels-browser --reporter=list`: [13 passed, 3 failed](../evidence/professional-ui/browser-panels-restoration.txt). All eight Partner/Brand navigation, context, relationship, privacy and registration regressions passed, as did both populated Professional tool workflows. The failures were the new panel test's empty-board locator/post-termination destination assumptions and the existing administration fixture-name collision described above. The [intermediate rerun](../evidence/professional-ui/browser-panels-final.txt) passed presentation on both devices and exposed the remaining post-termination assertion in the new test; the application behavior was preserved.

The final command is `npx playwright test professional-ui.spec.ts professional.spec.ts --grep 'Professional presentation|Professional pipeline panels|professional workspace' --project=desktop --project=mobile --output=/tmp/professional-panels-verified --reporter=list`. Its [results](../evidence/professional-ui/browser-panels-verified.txt) are **6 passed** and supersede the earlier failed cases. **All 16 distinct scoped browser journeys have passing evidence across the broad and final runs.** No assertion was removed or skipped to bypass a product failure. Screenshots at all five widths were inspected for placement, readable headings, wrapping, overflow and header access; the 320px final capture dismisses the transient acceptance notice before the screenshot. Partner/Brand regression screenshots linked below were refreshed from the broad run.

Earlier checks below retain their original scope.

## Professional header badge correction

**Confirmed (2026-11-08):** the Professional logo, wordmark and badge remain together on one line. Removed the brand's wrapping/width limits and all badge margin/mobile sizing overrides. The badge now matches Partner's pill: fully rounded corners, 12% white background, 12px text and 3px/8px padding. At 320px and 390px the Menu/account controls wrap to a second row. Only Professional header CSS changed; shared components, API and data behavior are preserved.

Inspected refreshed header screenshots at [320px](../evidence/professional-ui/header-320.png), [390px](../evidence/professional-ui/header-390.png), [768px](../evidence/professional-ui/header-768.png), [1024px](../evidence/professional-ui/header-1024.png) and [1440px](../evidence/professional-ui/header-1440.png). These supersede the header appearance in earlier workflow captures. Hub width captures, desktop/mobile after captures and menu captures were also refreshed by the existing presentation journey.

| Command (from `src/` unless stated) | Result |
| --- | --- |
| `npm run format -- resources/css/professional.css tests/Browser/professional-ui.spec.ts` | Passed |
| `npm run format:check:changed` | Passed; 52 changed frontend files |
| `npm run typecheck` | Passed |
| `npm run build` | Passed |
| `npm test -- tests/Frontend/ProfessionalShell.test.ts` | Passed; 7 tests |
| `npx playwright test professional-ui.spec.ts --grep 'Professional presentation' --project=desktop --project=mobile --output=/tmp/professional-badge-browser --reporter=list` | Passed; 2 tests (59.7s) |
| `git diff --check` (repository root) | Passed |

The existing presentation/navigation journey now checks all five requested widths, badge alignment/spacing and computed pill styles, keyboard opening/Escape/focus restoration for navigation and account menus, account menu bounds and all nine pages' horizontal overflow. Existing axe checks, destination checks and editor cancellation pass on desktop/mobile. Manual screen-reader/native Safari gates below remain unverified; no backend tests were required for this header-only correction.

## Environment and checks

The existing isolated `weavdash-professional` Compose app at localhost:8000 serves the source bind mount and production Vite bundle. Fixtures create synthetic accounts and client records; database runtime authorization remains unchanged. No deployment, real-account provisioning or external email is involved. A test-only override (`/tmp/weavdash-professional-ui-compose.yaml`) mounts the existing `/tmp/weavdash-professional-private` directory into the app: its test user is UID 1000, while the original named private-storage volume is owned by UID 33. The original named volume and repository Compose configuration are preserved.

Run npm commands in `src/`, Docker Compose commands at repository root with `--env-file src/.env -p weavdash-professional`.

| Command | Result |
| --- | --- |
| `npm run format:changed` and `npm run format:check:changed` | Passed, pinned Prettier; 52 supported changed files checked, including inherited changes |
| `npm run typecheck` | Passed |
| `npm test` | Passed: 17 files, 75 tests |
| `npm run build` | Passed |
| `git diff --check` | Passed |
| Planning/evidence relative-link validation | Passed; no missing targets |

New `ProfessionalShell.test.ts` covers Professional-only rendering, canonical detail URLs, excluded surfaces, retained destinations, subscription states, UTC dates, exact counts and every CSV link. `professional-ui.spec.ts` covers all nine screens, keyboard navigation, editor cancellation and populated Professional workflows. Existing version-acknowledgement tests now supply the shell's Inertia page context. Existing browser assertions were aligned with the inherited client labels and access-denial copy; application behavior and permission checks were not changed.

## Screenshots

The baseline captures precede this refinement. After captures use the same synthetic fixture setup at 1440px and 390px. Additional overflow checks cover all nine pages at 320/768/1024/1440px. Mobile device screenshots retain their device pixel ratio.

| Screen | Desktop before | Desktop after | Mobile before | Mobile after |
| --- | --- | --- | --- | --- |
| Hub | [Before](../evidence/professional-ui/before-desktop-hub.png) | [After](../evidence/professional-ui/after-desktop-hub.png) | [Before](../evidence/professional-ui/before-mobile-hub.png) | [After](../evidence/professional-ui/after-mobile-hub.png) |
| Client leads | [Before](../evidence/professional-ui/before-desktop-leads.png) | [After](../evidence/professional-ui/after-desktop-leads.png) | [Before](../evidence/professional-ui/before-mobile-leads.png) | [After](../evidence/professional-ui/after-mobile-leads.png) |
| Contacts | [Before](../evidence/professional-ui/before-desktop-contacts.png) | [After](../evidence/professional-ui/after-desktop-contacts.png) | [Before](../evidence/professional-ui/before-mobile-contacts.png) | [After](../evidence/professional-ui/after-mobile-contacts.png) |
| Onboarding | [Before](../evidence/professional-ui/before-desktop-onboarding.png) | [After](../evidence/professional-ui/after-desktop-onboarding.png) | [Before](../evidence/professional-ui/before-mobile-onboarding.png) | [After](../evidence/professional-ui/after-mobile-onboarding.png) |
| Templates | [Before](../evidence/professional-ui/before-desktop-outreach-templates.png) | [After](../evidence/professional-ui/after-desktop-outreach-templates.png) | [Before](../evidence/professional-ui/before-mobile-outreach-templates.png) | [After](../evidence/professional-ui/after-mobile-outreach-templates.png) |
| Profile | [Before](../evidence/professional-ui/before-desktop-profile.png) | [After](../evidence/professional-ui/after-desktop-profile.png) | [Before](../evidence/professional-ui/before-mobile-profile.png) | [After](../evidence/professional-ui/after-mobile-profile.png) |
| Invoices | [Before](../evidence/professional-ui/before-desktop-finance-invoices.png) | [After](../evidence/professional-ui/after-desktop-finance-invoices.png) | [Before](../evidence/professional-ui/before-mobile-finance-invoices.png) | [After](../evidence/professional-ui/after-mobile-finance-invoices.png) |
| Payments | [Before](../evidence/professional-ui/before-desktop-finance-payments.png) | [After](../evidence/professional-ui/after-desktop-finance-payments.png) | [Before](../evidence/professional-ui/before-mobile-finance-payments.png) | [After](../evidence/professional-ui/after-mobile-finance-payments.png) |
| Automation | [Before](../evidence/professional-ui/before-desktop-automation.png) | [After](../evidence/professional-ui/after-desktop-automation.png) | [Before](../evidence/professional-ui/before-mobile-automation.png) | [After](../evidence/professional-ui/after-mobile-automation.png) |

## Browser commands and outcomes

All commands run in `src/` against localhost:8000. Full results are retained in the linked logs.

| Command | Result |
| --- | --- |
| `COMPOSE_PROJECT_NAME=weavdash-professional npx playwright test professional-ui.spec.ts professional.spec.ts pipeline.spec.ts crm.spec.ts outreach-templates.spec.ts onboarding.spec.ts finance.spec.ts automation.spec.ts portals.spec.ts --project=desktop --project=mobile --output=/tmp/professional-ui-verified --reporter=list` | [Broad run](../evidence/professional-ui/browser-broad.txt): 27 passed, 4 failed, 1 existing skip; all four failures resolved in focused reruns below |
| `npx playwright test professional-ui.spec.ts professional.spec.ts --project=desktop --project=mobile --output=/tmp/professional-ui-final-check --reporter=list` | [Final Professional run](../evidence/professional-ui/browser-professional-final.txt): **6 passed**, after the final heading/shell correction |
| `COMPOSE_PROJECT_NAME=weavdash-professional npx playwright test onboarding.spec.ts --project=desktop --output=/tmp/professional-ui-onboarding-recheck --reporter=list` | [Final onboarding/publication run](../evidence/professional-ui/browser-onboarding-final.txt): **2 passed** |

**31 distinct executed journeys have passing results across the broad and final focused runs.** The existing automation test skips mobile under its earlier handoff rule; the new Professional workflow separately exercises mobile preferences, weekly scheduling, composition, approval and message download. No failing assertion was removed or converted to a skip.

The broad run's desktop upload failed because the app's test user could not write its image-owned private volume. The test-only mount described above repaired that environment; restarting the isolated app interrupted a publication request. Both complete desktop journeys then passed unchanged. The older Professional test expected the hub link's entire accessible name to be exactly “Client leads”; the new descriptive row includes its description. Its locator now selects that link within the main landmark by its title prefix. Earlier exploratory runs also exposed inherited onboarding labels and entitlement-message expectations that predated the existing working-tree changes; the tests now use the current labels/copy. Final Professional navigation scans corrected a lost foreground token and invalid mobile menu role nesting. Screenshot review corrected contacts' scoped heading override and aligned editor footer controls.

## Inspected workflow and navigation evidence

All nine initial screens were inspected at desktop/mobile sizes. Empty states, populated records, native editors, consent and confirmation controls, form errors/disabled states exercised by the existing tests, and menu keyboard behavior were reviewed. The final contact heading is also asserted at 34px desktop and 28px mobile to catch the scoped-style override found during screenshot review. Desktop screenshots use 1440px and mobile screenshots use 390px; the final populated canonical invoice detail retains the Professional shell at `/finance/invoices/{id}`.

| Workflow | Desktop | Mobile |
| --- | --- | --- |
| Lead editor | [Screenshot](../evidence/professional-ui/workflow-desktop-lead-editor.png) | [Screenshot](../evidence/professional-ui/workflow-mobile-lead-editor.png) |
| Contacts and reminders | [Screenshot](../evidence/professional-ui/workflow-desktop-contacts.png) | [Screenshot](../evidence/professional-ui/workflow-mobile-contacts.png) |
| Prepared template draft | [Screenshot](../evidence/professional-ui/workflow-desktop-templates.png) | [Screenshot](../evidence/professional-ui/workflow-mobile-templates.png) |
| Published profile | [Screenshot](../evidence/professional-ui/workflow-desktop-profile.png) | [Screenshot](../evidence/professional-ui/workflow-mobile-profile.png) |
| Canonical invoice detail | [Screenshot](../evidence/professional-ui/workflow-desktop-invoice-detail.png) | [Screenshot](../evidence/professional-ui/workflow-mobile-invoice-detail.png) |
| Receipt allocation | [Screenshot](../evidence/professional-ui/workflow-desktop-payments.png) | [Screenshot](../evidence/professional-ui/workflow-mobile-payments.png) |
| Onboarding review | [Screenshot](../evidence/professional-ui/workflow-desktop-onboarding-review.png) | [Screenshot](../evidence/professional-ui/workflow-mobile-onboarding-review.png) |
| Scheduling and approved draft | [Screenshot](../evidence/professional-ui/workflow-desktop-automation.png) | [Screenshot](../evidence/professional-ui/workflow-mobile-automation.png) |

- Menu disclosure: [desktop project at 390px](../evidence/professional-ui/menu-desktop.png), [mobile](../evidence/professional-ui/menu-mobile.png). Axe reports no violations with the menu open; both viewport modes retain Escape dismissal and trigger focus.
- Editor footers: [desktop contact](../evidence/professional-ui/editor-desktop-contact.png), [desktop reminder](../evidence/professional-ui/editor-desktop-reminder.png), [mobile contact](../evidence/professional-ui/editor-mobile-contact.png), [mobile reminder](../evidence/professional-ui/editor-mobile-reminder.png). Cancel closes each editor and restores its trigger; first-field focus is retained.
- Additional hub widths: [320px](../evidence/professional-ui/hub-320.png), [768px](../evidence/professional-ui/hub-768.png), [1024px](../evidence/professional-ui/hub-1024.png), [1440px](../evidence/professional-ui/hub-1440.png). All nine pages also pass overflow checks at those widths. The narrowest header wraps without clipping or hiding Menu/account access.
- Representative regressions: [Partner desktop](../evidence/professional-ui/regression-portal-partner-desktop.png), [Partner mobile](../evidence/professional-ui/regression-portal-partner-mobile.png), [Brand desktop](../evidence/professional-ui/regression-portal-brand-desktop.png), [Brand mobile](../evidence/professional-ui/regression-portal-brand-mobile.png). Their existing navigation, visual composition and relationship workflows are retained; Professional styles do not apply to them.

## Remaining manual gates

Manual screen-reader testing, native Safari and a dedicated throttled-network/manual loading-state review were not performed. Browser automation uses Chromium, including the mobile device configuration; it is not native iOS Safari acceptance. Existing UI-05, administration, provider delivery and release gates remain open. Backend/RLS acceptance suites were not rerun for this presentation-only refinement; the real local app and its existing authorization are exercised by the browser journeys. No production deployment or commercial activation occurred.
