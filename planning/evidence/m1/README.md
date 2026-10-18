# M1 UI Evidence

**Confirmed implementation evidence (2026-10-18, Asia/Shanghai):** Chromium desktop (1280 × 720) and mobile (390 × 664 CSS pixels, iPhone 13 emulation) journeys passed against the disposable Compose application. Mobile screenshots use the emulated device pixel ratio; foundation screenshots capture the full page.

| State | Desktop | Mobile |
|---|---|---|
| Saved prototype, viewport reference | [Prototype](prototype-desktop.png) | [Prototype](prototype-mobile.png) |
| Sign in, keyboard focus | [Login](login-desktop.png) | [Login](login-mobile.png) |
| Workspace name validation and focus | [Error](workspace-error-desktop.png) | [Error](workspace-error-mobile.png) |
| Disabled create submission | [Loading](workspace-loading-desktop.png) | [Loading](workspace-loading-mobile.png) |
| Created and renamed workspace | [Workspaces](workspaces-desktop.png) | [Workspaces](workspaces-mobile.png) |

The saved **Mockup** is a palette/layout reference, not an implemented CRM. Foundation screens implement only M1 account and workspace flows. The prototype is unchanged.

Browser checks verify the first keyboard focus target, validation/error association and focus after requests finish, disabled pending submission, password clearing, and absence of horizontal workspace overflow. Journeys also verify signed email verification, password reset, invalid login, and revocation of two independent database sessions. Synthetic reset/verification links come from a CLI-only fixture; external email delivery is not exercised.

Reproduce from `src/` with a running, migrated disposable Compose stack: `npm run test:browser`. Set `COMPOSE_PROJECT_NAME=weavdash` for the default Compose project; the test default is `weavdash-m1`. Fresh screenshots/traces are written to ignored `src/test-results/`.

[Security/accessibility results](security-accessibility.json) record 11 passing browser tests and one intentional skipped mobile duplicate of viewport-independent session attacks. All five roles, revoked selections, forged mutation fields, fixation, CSRF enforcement, cookie replay, reset-session revocation, and consumed reset links are covered. Twenty axe scans report zero violations. Two decorative workspace-initial contrast items received manual review: #8e1650 on #f2e2e9 yields 7.07:1; the initial is hidden from assistive technology and repeats the adjacent full name. No axe rule was disabled. Checks include keyboard disclosure, validation focus, 200-percent root font, and overflow. Fixes restore a visible mobile H1 and remove an unsupported generic-span ARIA label.

[Clean setup results](clean-setup.json) record a successful 253-second local run from source without copied dependencies or local credentials, using fresh database/Redis volumes. All 23 identity, six integration, 14 acceptance, 13 Vue, and 11 browser tests passed; types, build, migrations/grants, and RLS checks passed. The runner removed its disposable services and volumes. Existing host/build/browser caches may be reused. The pinned source-secret scan also passed with its synthetic positive control; it does not scan history or ignored local credentials.

**Assumption / Open:** automated Chromium/WebKit coverage does not establish native Safari behavior, screen-reader support, full browser zoom, or exhaustive accessibility/security compliance. External email delivery and link opening passed in the subsequent Brevo trial linked below. Production MFA policy is confirmed for operators and owners (D18); enforcement remains pending. Baseline remote CI passed; [remote evidence](remote-ci.json) records the run and scope. Expanded [remote verification](remote-ci.json) passed on `264e8e3`: 22 Chromium/WebKit desktop/mobile browser tests passed, two mobile session-attack duplicates were skipped, and 40 axe scans reported zero violations. Backend and fresh-setup jobs passed; the fresh setup completed in 305 seconds. A WebKit contrast failure during button opacity restoration was corrected by limiting button animation to colors. Native Safari, manual screen-reader use, and actual browser zoom remain open. M1 remains in progress.

[External mail trial](external-mail.json) records successful Brevo SMTP authentication, a browser password-reset request, and a verification notification handoff to the user-authorized inbox. The existing account was already verified and remained unchanged. The user confirmed receipt of both messages and that both links work. The app returned to the in-memory transport after the trial. Addresses, credentials, passwords, and signed URLs are omitted.
