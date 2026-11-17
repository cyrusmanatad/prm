# Enrollment QR code (B7) — implementation and verification

Scope: item B7 of the [administration handoff](implementation-handoff.md#part-b--administration-work-not-yet-implemented), authorized by D33 in [decisions](../shared/decisions.md). Date: 2026-11-17. Revision: branch `administration/b7-enrollment-qr`, based on `main` at `584e9f3`. Environment: disposable local Compose project `weavdash-admin` (`localhost:8010`). No shared, staging or production system was touched and no real operator was provisioned.

## What was implemented

- `/admin/security` shows a QR code above the setup key once a key has been generated. The copyable setup key stays as the manual alternative. Nothing is shown before a key exists or after enrollment.
- The code is drawn in the browser as inline SVG from the setup key already on the page. No image address, request or third-party service carries the key, and no server change was made: routes, props, validation and the 10-minute key expiry are unchanged.
- The code holds a standard `otpauth://totp/` address with issuer `WeavDash Admin`, the operator's sign-in email as the account label, and SHA-1, six digits and a 30-second period, matching server verification.
- **Confirmed (D33):** one new frontend dependency, `uqr` 0.1.3 (MIT, no dependencies of its own), pinned exactly.

Reused and new components: the page keeps `ContentSection`, `CopyReference` and `FormField`. New `features/admin/EnrollmentQr.vue` draws the code and `features/admin/enrollment.ts` builds the address. One new rule, `.adm-enrollment-qr` in `admin.css`, sets the size and border using the existing border token. The code is always black on white so that it scans in any theme.

## Commands and results

| Command (from `src/`) | Result |
|---|---|
| `npm run format:check:changed` | Passed |
| `npm run typecheck` | Passed |
| `npm test` | 23 files, 113 tests passed, including the new enrollment test in `AdminPages.test.ts` |
| `npm run build` | Passed |
| `PLAYWRIGHT_BASE_URL=http://localhost:8010 COMPOSE_PROJECT_NAME=weavdash-admin npm run test:browser -- professional.spec.ts --project=desktop --project=mobile` | 2 passed. The journey generates a key, checks that the code is visible, runs axe on the page and completes enrollment. |
| One-off check, not kept: the generated code was rasterized as the component draws it and read back with an independent reader (`jsqr`) | Decoded to the exact address |

The Playwright JSON reporter ended each run with `EACCES` writing `src/test-results/results.json`, because that directory is owned by root on this host. This is unrelated to the change and did not affect the test results.

## Rendering and accessibility findings

Inspected with temporary captures (removed) at 1280×720 desktop and 390×664 mobile.

- The code is 200 px square at both sizes, left-aligned with the section text, with no page-level overflow.
- It is exposed as an image named "QR code for authenticator setup"; the instructions and manual key are text. No axe violations on the page with the code shown.

## Unverified gates

- The code was not scanned with a physical authenticator app; it was verified by decoding it in software.
- The same journey was first tried against the older `weavdash-professional` stack on `localhost:8000` and failed at sign-in with a server error, before reaching this change. That stack was not investigated.
- WebKit was not run locally (the host lacks its libraries); it runs in CI on the pull request.
- Native Safari, screen-reader and browser-zoom review: not done (handoff item B15).
