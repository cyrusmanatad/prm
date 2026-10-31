# M5 First-Slice Verification Evidence

**M5-04 (2026-10-31):** [reminder and weekly digest/review delivery evidence](scheduled-delivery/README.md), including PostgreSQL/Redis/SMTP recovery, consent races, desktop screenshots and remaining acceptance gates.

Status: local automated checks passed on 2026-10-23. Scope: durable outbox and explicitly requested partner invitation email only; full M5 remains incomplete. See the [contract](../../m5-notification-contract.md) and [status](../../m5-status.md).

Subsequent M5-01 onboarding invitation/replacement email has its own [verification and screenshots](onboarding-email/README.md). Results below retain the original first-slice scope.

No external email is sent by these checks. Acceptance uses mail fakes, the local array transport and a loopback SMTP sink; browser fixtures reject external transports and use synthetic `example.test` identities. A provider delivery/recipient link trial is still required separately.

## Commands and results

Docker commands run from the repository root with `docker compose --env-file src/.env`; npm/Playwright commands run from `src/`. Final backend checks used packaged images. During development, source-mounted checks found and fixed an invitation row-lock/RLS issue and test queue/SMTP configuration problems. Final browser checks wait for the completed invitation dialog before closing it; final logs replace those earlier failed runs.

Captured text logs have trailing whitespace and redundant final blank lines removed; command output and results are otherwise preserved.

| Gate | Command after Compose prefix, or frontend command | Result / evidence |
|---|---|---|
| Packaged builds | `build app migrate acceptance worker scheduler` | Passed |
| Full acceptance | `run --rm --no-deps acceptance` | **46 tests, 987 assertions**; [log](acceptance.txt) |
| PostgreSQL integration | `run --rm --no-deps acceptance vendor/bin/phpunit -c phpunit.pgsql.xml` | **27 tests, 503 assertions**; [log](integration.txt) |
| Unit/identity/feature | `run --rm --no-deps app php artisan test --compact` | **29 tests, 256 assertions**; [log](unit-feature.txt) |
| Fresh notification suite | `run --rm --no-deps -e DB_DATABASE=weavdash_m5_clean acceptance vendor/bin/phpunit -c phpunit.acceptance.xml --filter NotificationOutboxTest` | **10 tests, 129 assertions**; [log](notification-acceptance.txt) |
| Fresh schema | Empty migration install, `migrate:rollback --step=1 --force`, migrate reapply, `php scripts/grant-runtime.php`; catalog SQL below | Passed; [catalog log](schema.txt) |
| PHP style | `run --rm --no-deps acceptance vendor/bin/pint --test` with the 11 changed/new PHP files | Passed; controller, service, job, mailable, console route, mail config, migration and four test/fixture files |
| Frontend style/types/build | `npm run format:changed`, `npm run format:check:changed`, `npm run typecheck`, `npm run build` | Passed; both changed frontend files use pinned Prettier |
| Frontend interactions | `npm test` | **21 tests passed** |
| Chromium browser | `COMPOSE_PROJECT_NAME=weavdash npx playwright test tests/Browser/notifications.spec.ts tests/Browser/portals.spec.ts --project=desktop --project=mobile` | **8 passed, 14 Axe scans with zero violations**; [final log](browser.txt); notification email selection/capture/cancellation and existing partnership/login/registration journeys |
| Source secrets | `python3 src/scripts/check-secrets.py` | Positive control and source scan passed; [log](source-secrets.txt) |
| Documentation/source | Relative link check of changed documentation and `git diff --check` | Passed |

The fresh database was created separately with owner `weavdash_migrator`. Catalog verification ran `psql -v ON_ERROR_STOP=1` against that database using [notification-schema-check.sql](../../../src/tests/Fixtures/notification-schema-check.sql). It verifies forced RLS, three non-delete policies, the composite invitation/workspace foreign key, migration-owned security-definer helpers with fixed search paths, removal of PUBLIC execution and explicit runtime grants. No full historical clean-setup/load/production recovery matrix is implied. The single-user checker was extended for M5; host PostgreSQL binaries remain unavailable.

The final app, worker and scheduler are running locally. `schedule:list` shows the every-minute dispatcher. Worker and scheduler both reported `MAIL_MAILER=array`; a manual scheduler `notifications:dispatch` smoke check completed successfully. Local capture does not establish provider receipt.

Notification tests validate atomic rollback of business intent/audit, explicit send consent, encrypted capabilities, scalar queue payloads, duplicate publication/consumption, authorization/verification changes, expiration/revocation/acceptance cancellation, refusal of log transport, foreign-workspace/lower-role denial and canonical links under a forged request host. One test deletes only its isolated Redis queue to establish durable recovery. Another kills a real worker while the loopback sink holds SMTP DATA, reclaims the real Redis reservation without a second attempt, and reconciles the stale send to uncertainty. No test claims confirmed remote mailbox receipt or exactly-once SMTP delivery.

## Visual evidence and review

The UI reuses `FormField`, the shared `Button`, `ContentSection`, foundation colors/focus patterns and the existing portal dialog. Desktop/mobile screenshots were inspected for readable controls, dialog containment, wrapping, established margins and absence of page overflow. Axe runs in both the notification and existing portal journeys. The invitation history uses the same horizontal padding as the brand dashboard.

- [Desktop invitation dialog](notification-dialog-desktop.png)
- [Mobile invitation dialog](notification-dialog-mobile.png)
- [Desktop delivery/cancellation history](notification-history-desktop.png)
- [Mobile delivery/cancellation history](notification-history-mobile.png)

Provider transport/recipient delivery and actual email-link opening require a separately authorized trial. Native Safari/WebKit, manual screen-reader/full zoom acceptance and prior release gates remain unverified in this slice.
