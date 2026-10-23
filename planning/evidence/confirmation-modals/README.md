# App-wide modal confirmations

Date: 2026-10-23. UI-01 follow-up on `ui/confirmation-modals`, based on `925c0af`; implementation and evidence accompany this record. **Confirmed:** UI-01 is required across current and future milestones. Local development evidence; inherited production/manual release gates remain separate.

## Implementation

`ConfirmationDialog` is mounted once alongside the Inertia app and teleported to the document body. `confirmAction(title, description, callback, eventOrTrigger)` opens a native `showModal()` dialog before invoking the existing request handler. Pass the triggering event/element so pointer and keyboard users return to the actual trigger, including buttons inside the lead drawer. After successful deletion removes a trigger, focus falls back to the page heading.

The shared component reuses the foundation palette/card/controls, shared UI button and invitation-dialog dimensions/backdrop. It focuses Cancel, contains Tab/Shift+Tab, supports Escape while idle, disables controls while pending, guards repeated submits, shows/focuses server errors inside the modal and closes on success. Stale records and uncertain network/HTTP outcomes require cancellation and reloading before retrying. Existing server permissions, validation, reasons, passwords, command IDs and version checks remain in the original handlers. No backend/API/schema changes.

Coverage: partnership ending from both portals; pending partner invitation revocation/email cancellation; lead/template/contact/reminder deletion and contact unlinking; onboarding document removal, invitation replacement and access revocation; public lead-link revocation and media-kit unpublishing; draft/receipt voiding; allocation reversal (including its reason); all-session sign-out (including current password). Routine saves, navigation and reversible unsaved edits remain immediate. Existing acceptance/consent controls remain.

## Verification

All 34 affected desktop/mobile journeys have passing local evidence across the broad run and targeted follow-up. The [broad run](browser.log) passed 30/34: two failures reproduced the inherited M1-06 target-size finding, and two exposed a navigation synchronization race in the newly extended CRM test. The workspace portal link now has a 44px minimum target height; the CRM test waits for Inertia filter navigation to finish before selecting the reminder. The [follow-up run](browser-follow-up.log) passed all 12 tests, including those four failures and related workspace/session regressions. No failed gate is being waived.

From `src/`:

- `npm run format:changed` and `npm run format:check:changed` — 28 changed frontend/test files formatted/checked using pinned Prettier; [log](format.log).
- `npm run typecheck` — passed; [log](types.log).
- `npm test` — 26 tests across six files passed, including confirmation cancellation/Escape/focus, duplicate submissions, stale errors and workspace confirmation dispatch; [log](unit.log).
- `npm run build` — passed; [production build log](production-build.log).

From the repository root: `docker compose --env-file src/.env -p weavdash-m1 build app migrate`, then `docker compose --env-file src/.env -p weavdash-m1 up -d --wait app`. Browser fixtures use synthetic accounts in the existing local PostgreSQL/RLS environment. No external mail is sent.

Browser verification uses `COMPOSE_PROJECT_NAME=weavdash-m1 npm run test:browser -- <specs> --project=desktop --project=mobile`. The shared browser helper checks native modality, accessible names, Cancel initial focus, Tab containment, trigger restoration, zero mutation requests on open/Cancel/Escape, one request on confirmation and an Axe scan while each modal is open. The delayed-session test checks disabled controls and repeated submit events before releasing the request, then focused password errors. Finance exercises stale void errors and disabled retry; onboarding covers document removal/replacement/revocation; notification tests retain local capture/cancellation assertions.

## Browser commands and visual review

From `src/`, with `COMPOSE_PROJECT_NAME=weavdash-m1`:

```sh
npm run test:browser -- confirmation-errors.spec.ts crm.spec.ts finance.spec.ts pipeline.spec.ts outreach-templates.spec.ts portals.spec.ts onboarding.spec.ts foundation.spec.ts notifications.spec.ts accessibility.spec.ts --project=desktop --project=mobile --output=/tmp/weavdash-confirmation-final-results --reporter=list
npm run test:browser -- accessibility.spec.ts confirmation-errors.spec.ts crm.spec.ts foundation.spec.ts --project=desktop --project=mobile --output=/tmp/weavdash-confirmation-rerun-results --reporter=list
```

The app was rebuilt after the workspace target-size fix using `docker compose --env-file src/.env -p weavdash-m1 build app` and restarted with `up -d --wait app`. Follow-up Axe scans pass for workspace/security errors and larger root text, preserving the existing WCAG 2.2 assertions. `git diff --check` and new documentation relative-link checks pass.

Inspected desktop/mobile modal rendering, readable record/consequence text, focus indication, responsive controls, lead-drawer overlay and server-error states. Retained synthetic screenshots:

| State | Desktop | Mobile |
|---|---|---|
| Brand partnership | [View](brand-partnership-desktop.png) | [View](brand-partnership-mobile.png) |
| Partner partnership | [View](partner-partnership-desktop.png) | [View](partner-partnership-mobile.png) |
| Lead drawer deletion | [View](lead-deletion-desktop.png) | [View](lead-deletion-mobile.png) |
| Allocation reversal and reason | [View](allocation-reversal-desktop.png) | [View](allocation-reversal-mobile.png) |
| Stale invoice error | [View](stale-invoice-desktop.png) | [View](stale-invoice-mobile.png) |
| Password error | [View](password-error-desktop.png) | [View](password-error-mobile.png) |
| Document removal | [View](document-removal-desktop.png) | [View](document-removal-mobile.png) |
| Media kit unpublishing | [View](unpublish-media-kit-desktop.png) | [View](unpublish-media-kit-mobile.png) |
| Contact deletion | [View](delete-contact-desktop.png) | [View](delete-contact-mobile.png) |

## Limits

Native macOS/iOS Safari, current Linux WebKit, actual screen readers, actual browser 200%/400% zoom and current-candidate remote CI remain unverified. The existing larger-root-font test is not a substitute for actual browser zoom. M5/M6 implementation, provider-receipt, live-storage/privacy, production MFA and release/UAT gates remain open in the [register](../../remaining-tasks.md). This task authorizes no production rollout or external delivery.
