# M5-01 Onboarding Invitation Email Evidence

Verified locally on 2026-10-23 on `m5/onboarding-invitation-email`, working tree based on `ce2d9bd`. [Source fingerprints](source-sha256.txt) identify the changed application/test files. See the [recipient/access contract](../../../m5-onboarding-email-contract.md) and [M5 status](../../../m5-status.md). This completes M5-01 implementation/local verification, not M5 or provider delivery.

## Commands and results

Compose prefix below: `docker compose --env-file src/.env -p weavdash-m1`, run from the repository root. Frontend commands run from `src/`. Backend checks used rebuilt packaged images. Tests use synthetic identities, local array mail and the existing loopback SMTP/crash fixtures; no external email was sent.

| Gate | Command / scope | Result |
|---|---|---|
| Packaged images | `build app migrate acceptance worker scheduler` | Passed |
| Notification acceptance | `run --rm -T acceptance vendor/bin/phpunit -c phpunit.acceptance.xml --filter 'OnboardingNotificationTest\|NotificationOutboxTest'` | **17 tests / 284 assertions**; [log](notification-acceptance.txt) |
| Full fresh PostgreSQL acceptance | `run --rm -T -e DB_DATABASE=weavdash_m5_onboarding_20261023 acceptance vendor/bin/phpunit -c phpunit.acceptance.xml` | **53 tests / 1,142 assertions**; [log](acceptance.txt) |
| PostgreSQL integration | `run --rm -T acceptance vendor/bin/phpunit -c phpunit.pgsql.xml` | **27 tests / 503 assertions**; [log](integration.txt) |
| Identity/feature regression | `run --rm -T --no-deps app php artisan test --compact` | **29 tests / 256 assertions**; [log](identity.txt) |
| Fresh schema and rollback | Migrate, rollback newest migration, reapply, `php scripts/grant-runtime.php`, catalog checks; repeat rollback/reapply with populated synthetic data | Passed; [log](schema.txt) |
| Frontend | `npm run format:changed`, `npm run format:check:changed`, `npm run typecheck`, `npm test`, `npm run build` | Six changed supported frontend files checked; **26 component tests**, types and build passed |
| Chromium desktop/mobile | `COMPOSE_PROJECT_NAME=weavdash-m1 npm run test:browser -- onboarding-notifications.spec.ts onboarding.spec.ts notifications.spec.ts --project=desktop --project=mobile --output=/tmp/weavdash-onboarding-email-browser --reporter=list` | Six existing journeys passed; new journey initially expected incorrect error-page wording in both viewports; [broad log](browser.txt) |
| Browser follow-up | `COMPOSE_PROJECT_NAME=weavdash-m1 npm run test:browser -- onboarding-notifications.spec.ts --project=desktop --project=mobile --output=/tmp/weavdash-onboarding-email-browser-followup --reporter=list` | Both corrected journeys passed; **all eight affected journeys have passing evidence**, with clean embedded Axe checks; [follow-up log](browser-followup.txt) |
| PHP style | `vendor/bin/pint --test` with the six changed/new PHP paths | Passed |
| Source/whitespace | `python3 src/scripts/check-secrets.py`, `git diff --check` | Positive control and source scan passed (530 reviewable files); whitespace check passed |
| Documentation | Local targets/anchors across changed planning documents | Passed |

The isolated database was created with owner `weavdash_migrator`. The first full acceptance attempt stopped at missing runtime privileges on `users`; applying the README's required `scripts/grant-runtime.php` setup step resolved this, and the full rerun passed. Catalog checks use [notification-schema-check.sql](../../../../src/tests/Fixtures/notification-schema-check.sql) with `psql -v ON_ERROR_STOP=1`; they verify forced RLS, non-delete policies, both composite targets, generation uniqueness and restricted maintenance-function privileges. The populated rollback preserved **28 onboarding requests and 15 partner email records** while removing the 18 onboarding email records specific to the rolled-back feature. Reapply and catalog checks passed afterward. The normal development database was not rolled back.

Onboarding tests cover opt-in/validation, canonical links under forged Host headers, encrypted recipient/capability intent, minimal message content, scalar queue payloads, duplicate delivery, single-use exchange, replacement sessions and stale versions, expiry/revocation/consumption, role/membership/verification changes, denied foreign reads/writes, capability isolation, atomic rollback, composite targets and generation uniqueness, actual Redis queue loss/replay, transport uncertainty and stale reservations. Existing partner SMTP capture, concurrent reservation and SIGKILL/reclaim tests pass through the shared delivery service. Account notification regressions pass separately.

## UI review

Reused `FormField`, shared `Button`, `ContentSection`, `FoundationShell`, the lead drawer and `confirmAction`/`ConfirmationDialog`. Added `OnboardingEmailFields` for the repeated opt-in/recipient controls and shared `emailStates` for consistent partner/onboarding delivery wording. No new styling system was introduced.

Desktop and mobile screenshots were inspected for alignment with the existing palette, field spacing, readable wrapping, focus styles and overflow. Browser checks cover unchecked defaults, explicit recipient entry, local capture, usable invitation access, replacement ending the prior session, cancellation on revocation and denial of the revoked replacement. Confirmation checks exercise initial Cancel focus, cancellation without mutation, Escape, focus restoration and contained keyboard navigation. All embedded Axe checks passed.

- [Desktop email controls](controls-desktop.png) and [mobile email controls](controls-mobile.png).
- [Desktop replacement confirmation](replacement-desktop.png) and [mobile replacement confirmation](replacement-mobile.png).
- [Desktop delivery history](history-desktop.png) and [mobile delivery history](history-mobile.png).

## Handoff and remaining gates

App, worker and scheduler are restored on Compose project `weavdash-m1`; all three reported `mail.default=array`. The scheduler dispatcher smoke check completed successfully. The app is at `http://localhost:8000`; the isolated synthetic database `weavdash_m5_onboarding_20261023` is retained at the current schema.

M5-02 lifecycle recipients/return links, M5-03–M5-10 scheduling/approval/recovery/provider work, and inherited M6 release gates remain open. Provider receipt and real email-client link handling were not tested. Native Safari/Linux WebKit, manual screen-reader/full browser zoom, current-candidate remote CI and the full historical clean-setup matrix were not rerun for this slice. The PostgreSQL checks above are migration/runtime evidence, not a production readiness or external delivery claim.
