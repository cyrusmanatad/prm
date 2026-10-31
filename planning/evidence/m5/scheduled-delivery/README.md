# M5-04 scheduled reminder and review delivery — 2026-10-31

Status: **implemented; backend/desktop locally verified; small-screen/provider acceptance pending**. Working tree based on `f0f6066` on `m5/onboarding-lifecycle-email`. See the [delivery contract](../../../m5-scheduled-delivery-contract.md). D28 retains Claude's administration/small-screen scope and carries UI-05 forward; M5/M6 are incomplete.

## Delivered behavior

- Reminder and weekly-digest ready-run consumers append outbox intent and audit while completing the run atomically. Redis jobs contain scalar routing IDs only; queue loss at either stage is recoverable from PostgreSQL. Replay cannot create a duplicate notice or repeat a reserved send.
- Current verified account is the only recipient. Optional preferences and explicit schedule consent apply per actor/workspace. Membership, verification, pilot entitlement, consent/preferences, schedule/run/rule and reminder versions are rechecked before consumption and reservation. Other administrators cannot inspect another actor's scheduled history. Existing account/invitation/lifecycle email paths retain their behavior.
- One-shot reminder notices and weekly local-time due-reminder/onboarding-review counts use authenticated app links with no record details. Weekly scheduling retains M5-03 DST behavior and coalesces downtime; newer ready/completed occurrences supersede older pending reviews. Follow-up draft runs remain unconsumed for M5-05.
- The shared outbox's SMTP/local capture restrictions and conservative uncertain-send handling remain intact. Queued, reserved, accepted-by-transport, captured, cancelled and uncertain outcomes are distinct. No external provider message was sent.
- `/automation` adds one-shot reminder controls and own delivery history. New ReminderSchedule/NoticeHistory reuse ContentSection, FormField, SelectField, Button and validation focus; existing preferences/SavedSchedules/ConfirmationDialog handle withdrawal. No new CSS system is introduced.

## Verification

| Gate | Result / evidence |
|---|---|
| Full PostgreSQL acceptance | **86 tests / 1,918 assertions** pass: [log](acceptance-full.txt). Includes existing account/invitation/lifecycle, isolation, process-kill/reclaim and scheduler recovery regressions. |
| New scheduled-delivery suite | **12 tests / 301 assertions** pass after final rollback/reapply: [log](acceptance-after-reapply.txt). Covers current-basis rejection at consumption/direct reservation/reconciliation, role and actor/tenant/capability isolation, composite run ownership, atomic rollback, real Redis loss/replay, loopback SMTP, uncertain delivery, both real consent/reservation lock orderings, weekly outage coalescing and exclusion of follow-up drafts. Initial ten-test suite also [passed](acceptance-initial.txt) before the two final recovery/race cases were added. |
| PostgreSQL integration | **29 tests / 528 assertions**, [log](integration.txt). |
| PHP identity/unit/feature | **30 tests / 265 assertions**, [log](php.txt), including inherited DST cases. |
| Migration/catalog/schema | Fresh synthetic migration and initial [rollback/reapply](migration.txt) pass. Final [rollback/reapply](rollback-reapply.txt) preserves **85 reminders, 114 schedules, 111 runs, 62 onboarding requests and 70 non-scheduled notifications**: [before](preserved-before.txt), [after](preserved-after.txt). Only scheduled outbox rows are intentionally discarded by this rollback. [Catalog keys/grants](catalog.txt) and [standalone DDL](schema.txt) pass. |
| Frontend | Pinned formatting **four supported files**, types, **51 component tests / 13 files**, host build and final image build pass. [Summary](frontend.txt), [format](format.txt), [types](types.txt), [Pint](pint.txt), [final image build](docker-build.txt). |
| Desktop Chromium | **Two distinct journeys pass** across the [initial run](browser-initial.txt) (existing preferences/weekly consent) and [follow-up](browser-followup.txt) (new reminder schedule, actual Redis capture, truthful history, confirmed withdrawal). New flow has zero Axe violations at 1440px/1920px and no overflow; Cancel focus, Escape and trigger restoration pass. Existing automation journey retains its weekly/preference withdrawal checks. |
| Running source | App image and host hashes match all 12 selected backend, schema, UI and acceptance files: [source hashes](source-sha256.txt). Final host-only schema checker/catalog fixture and browser wait correction are newer than the image test copy; product files match. |
| Source scan | **Not a clean gate:** positive control passes; scanner reports one verified pre-existing SHA-256 evidence false positive, [log](secrets.txt). The value on `planning/evidence/m5/schedules/source-sha256.json:7` is the SHA-256 of `planning/api-design.md` at baseline `f0f6066`, confirmed with `git show f0f6066:planning/api-design.md` piped to `sha256sum`. It is a source-content checksum, not a credential. Scanner/rules were not changed or suppressed; no additional finding is reported. |

The first reminder browser attempt issued a JSON mutation before workspace creation's session update completed (409, select workspace). The test now waits for the Automation link/navigation before creating its reminder, and the full reminder journey passes. No product behavior changed for that test correction. Sandbox restrictions required ordinary approved escalation for Docker, the formatter subprocess, browser and source scanner.

## Desktop screenshots

[1440px](scheduled-delivery-1440.png) and [1920px](scheduled-delivery-1920.png) were visually inspected: established palette, typography, spacing, shared cards/controls, labelled inputs and readable capture status. These screenshots certify the tested desktop scope only; small-screen acceptance stays with Claude and UI-05 exact reference acceptance remains open.

## Reproduction

Compose project `weavdash`, local `http://localhost:8000`, PHP 8.5.11/PostgreSQL 16/Redis, array mail. Synthetic backend database: `weavdash_m5_delivery_20261031`. Separate migration/control credentials are absent from web/worker/scheduler. No existing-data cleanup, owner provisioning, production operation or external mail occurred.

From the repository root (create the synthetic database only once):

```sh
docker compose --env-file src/.env -p weavdash build app migrate acceptance worker scheduler
docker compose --env-file src/.env -p weavdash exec -T postgres createdb -U postgres -O weavdash_migrator weavdash_m5_delivery_20261031
docker compose --env-file src/.env -p weavdash run --rm -T --no-deps -e DB_DATABASE=weavdash_m5_delivery_20261031 migrate
docker compose --env-file src/.env -p weavdash run --rm -T --no-deps -e DB_DATABASE=weavdash_m5_delivery_20261031 migrate php scripts/grant-runtime.php
docker compose --env-file src/.env -p weavdash run --rm -T --no-deps -e DB_DATABASE=weavdash_m5_delivery_20261031 acceptance
docker compose --env-file src/.env -p weavdash run --rm -T --no-deps -e DB_DATABASE=weavdash_m5_delivery_20261031 app vendor/bin/phpunit -c phpunit.pgsql.xml
docker compose --env-file src/.env -p weavdash run --rm -T --no-deps app php artisan test --compact
docker compose --env-file src/.env -p weavdash exec -T postgres psql -U postgres -d weavdash_m5_delivery_20261031 -v ON_ERROR_STOP=1 < src/tests/Fixtures/scheduled-notice-schema-check.sql
python3 src/scripts/check-postgres-schema.py
python3 src/scripts/check-secrets.py
```

The final rollback uses only this synthetic database: `migrate php artisan migrate:rollback --step=1 --force`, reapply, runtime grants, compare preserved counts, then `acceptance vendor/bin/phpunit -c phpunit.acceptance.xml --filter ScheduledNotificationTest`. Never rehearse rollback on the main/shared database.

From `src/`:

```sh
vendor/bin/pint --test --dirty
npm run format:check:changed
npm run typecheck
npm test
npm run build
COMPOSE_PROJECT_NAME=weavdash npm run test:browser -- automation.spec.ts scheduled-delivery.spec.ts --project=desktop
```

For deterministic browser capture, pause local worker/scheduler before these tests and restore them afterward. The browser-only migration fixture matures only synthetic `m1-browser-scheduled-*` identities; processing uses runtime commands and local array capture. Retain array transport for ordinary development. At handoff the main app has the additive M5-04 migration and rebuilt app/worker/scheduler are running. No test databases were removed.

## Handoff

**Next backend task: M5-05 follow-up drafts and approval inbox**, with explicit recipient/content/send contract and manual/non-AI path. Preserve M5-04's account-only notices, current-basis locks, durable replay, coalescing and no uncertain resend. Follow-up/reply/approval work, rate budgets, operator reconciliation and broader service/provider outage evidence remain M5-05–M5-10.

Small-screen testing/refinement belongs to Claude. Provider receipt remains M5-09; administration, UI-05, current-candidate remote CI/clean setup/WebKit, manual accessibility/native Safari/full zoom and production readiness remain open. The source scan false positive above is recorded rather than claimed as a clean scanner pass.
