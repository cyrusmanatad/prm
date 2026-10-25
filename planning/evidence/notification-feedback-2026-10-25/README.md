# UI-07 notification feedback — 2026-10-25

**Result: UI-07 complete for scoped local acceptance. M5/M6 and inherited release gates remain open.**

## Scope and behavior

**Confirmed session scope:** the user held administrator implementation for this session and authorized continuing development. UI-07 was verified on `fix/notification-feedback`, based on merged `main` at `396b0e5`; the user subsequently requested a local commit on the new `fix/ui-07-notification-feedback` branch. ADM-01–ADM-07 remain required and unimplemented; no real owner provisioning or data cleanup occurs.

Partner and onboarding invitation creation now report **email queued**, rather than sent. Onboarding replacements retain the explicit previous-session closure warning alongside the queued-email message. Existing local-capture, transport-acceptance, cancellation and uncertainty history remains authoritative; queueing does not prove external receipt. One-time-link guidance, recipients, permissions, versions and modal confirmations are preserved.

The shared `useNotify`/`useFlashToast` and Toastify theme are reused. Flash registration follows app/plugin mounting; repeated successful actions get independent announcements, while history navigation does not replay success. Action and network errors retain dismissible guidance; successful navigation clears recovered network feedback. Network failures advise checking the current record before retrying and suppress the handled Inertia rejection. Field validation and stale-edit recovery remain inline.

Clipboard operations use distinct pending IDs and guard concurrent writes. Rejected/unavailable clipboard access is handled without an unhandled rejection and leaves manual-copy guidance beside the unchanged editable draft. Reduced motion uses static lifecycle keyframes so both manual dismissal and automatic timers work. Notification containers follow the active modal, keeping announcements and close controls out of the browser's inert background, and return after closure/unmount. The popover width and height are bounded to the viewport, excess notifications can scroll, and long recipients wrap. The unreliable capped Toastify queue is removed so a later action result cannot be dropped behind earlier messages.

## Verification

- Pinned Prettier: **pass**, 13 changed supported frontend files; four-space indentation preserved.
- Type checking: **pass**. Vitest: **51 passed in 13 files**, including initial/repeated/history flash behavior, error persistence and separate clipboard operations.
- [Production build](build.txt): **pass**. Final Docker app/migrate images built successfully; the final browser run uses the app image rather than the interim synchronized assets.
- [PHP unit/feature tests](php.txt): **29 passed / 256 assertions**. [Changed-controller Pint](pint.txt): **pass**, two files. Browser requests additionally exercise the real PostgreSQL-backed invitation endpoints and returned feedback; no schema, tenant query or permission change.
- [Final broad browser run](browser.txt): **21 passed / 1 transient contrast failure**. Axe measured the replacement warning during its partly transparent entrance. The confirmation helper now waits for root toast animations to settle (not descendant timer bars), retaining every Axe rule. The [desktop/mobile onboarding follow-up](browser-a11y-follow-up.txt) passes **2/2**. All **22 distinct affected Chromium desktop/mobile journeys** have passing evidence across those runs; this is not a claim of one clean 22/22 invocation. Keyboard Cancel focus, full dialog traversal including notification close controls, Escape/trigger restoration, one-request submission, field validation, stale edits, public links, finance and clipboard recovery remain covered. Notification bounds pass at 320/768/1024/1440px; desktop/mobile screenshots were inspected.
- Source scan with synthetic positive control: **pass**, no findings. [Planning link validation](planning-links.json): **pass**; Git whitespace checks pass.

The [initial run](browser-initial.txt) reproduced inert modal notifications and broken reduced-motion dismissal, and exposed the stale onboarding button assertion. It was interrupted after 6 passes / 3 failures to test fixes. The [focused run](browser-focused.txt) verified the repaired layering/dismissal but exposed a history-navigation timing assumption and a confirmation helper that assumed only two controls. The helper now checks wrapping to the actual final visible button, including notification close controls, and back to Cancel; cancellation, Escape, trigger restoration, Axe and one-request checks remain intact. The [subsequent regression run](browser-before-lifecycle-fix.txt) exposed notification entrance timing, repeated popover reopening that interrupted lifecycle feedback, and an auto-dismiss race in the test cleanup helper. Existing open popovers are now left open, hit-testing polls until entrance settles, and cleanup retries against current visible controls. All [four targeted lifecycle journeys](browser-lifecycle.txt) then passed. The [next broad run](browser-before-queue-fix.txt) passed 20 journeys and exposed the intermittent capped-queue loss plus a mobile assertion that incorrectly required two transient announcements to overlap. The [sanitized lifecycle observation](queue-race-observation.json) records the server success event while earlier notices occupied the cap. After removing the cap and bounding the visible stack, the [isolated document-removal journey passed twice](browser-queue-probe.txt), and the [mobile repeated-announcement follow-up passed](browser-mobile-follow-up.txt). The permanent mobile assertion verifies a new announcement ID rather than simultaneous old/new visibility. Temporary diagnostic instrumentation was removed. Final results supersede these diagnostic runs.

## Commands

From `src/`:

```sh
npm run format:changed
npm run format:check:changed
npm run typecheck
npm test
npm run build
COMPOSE_PROJECT_NAME=weavdash npm run test:browser -- flash-feedback.spec.ts notifications.spec.ts onboarding-notifications.spec.ts outreach-templates.spec.ts foundation.spec.ts finance.spec.ts onboarding.spec.ts confirmation-errors.spec.ts --project=desktop --project=mobile --output=/tmp/weavdash-notification-feedback-queue-final --reporter=list
COMPOSE_PROJECT_NAME=weavdash npm run test:browser -- 'onboarding-notifications.spec.ts$' --project=desktop --project=mobile --output=/tmp/weavdash-notification-feedback-a11y-final --reporter=list
```

From the repository root, build app/migrate and start app using `docker compose --env-file src/.env -p weavdash`; run `exec -T app php artisan test --compact`, changed-controller `vendor/bin/pint --test`, and `python3 src/scripts/check-secrets.py`. Docker/process restrictions required approved execution outside the sandbox. Worker/scheduler were temporarily stopped for deterministic notification capture and restored at handoff. All browser identities and records are synthetic, and delivery uses the local array transport.

## Screenshots and tested source

- Queued invitation over its modal: [desktop](partner-queued-desktop.png), [mobile](partner-queued-mobile.png).
- Persistent clipboard recovery after toast dismissal: [desktop](clipboard-desktop.png), [mobile](clipboard-mobile.png).
- Network guidance: [desktop](network-desktop.png), [mobile](network-mobile.png), [320px](network-320.png), [768px](network-768.png), [1024px](network-1024.png), [1440px](network-1440.png).
- Honest onboarding capture/cancellation history: [desktop](onboarding-history-desktop.png), [mobile](onboarding-history-mobile.png).

[Source/test fingerprints](source-sha256.json) identify the verified source/test change over `396b0e5`. All seven recorded [runtime source fingerprints](runtime-sha256.txt) match the checkout; the Docker-built manifest hash records the actual browser-tested bundle separately from the host build. [Source scan](source-scan.txt) passes its positive control and checks 743 reviewable files with no findings. Text logs have ANSI color/trailing whitespace normalized; results are preserved.

## Remaining gates

This slice does not complete M5/M6, administration, broader UI-05 reference/deviation acceptance or M5-09 provider receipt. Full PostgreSQL acceptance/process-crash suites, Linux WebKit/native Safari, actual screen-reader/full browser zoom, current-candidate remote CI and production operations are not newly certified here. No external message, production deployment, database cleanup or push is performed.
