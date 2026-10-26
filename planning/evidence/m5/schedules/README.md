# M5-03 scheduling foundation — 2026-10-26

Status: **backend and desktop locally verified; small-screen acceptance pending**. Working tree based on `258136f` on `m5/onboarding-lifecycle-email`. See the [implementation contract](../../../m5-schedules-contract.md). D28 carries UI-05 acceptance forward and delegates administration/small-screen work to Claude; those gates remain open.

## Delivered behavior

- Persisted named reminder, weekly digest and follow-up draft rules, rule/schedule/preference/subject versions, per-person/workspace IANA timezone and opt-in preferences. No existing records are backfilled; no external delivery is added in this slice.
- Explicit schedule consent, append-only consent events and versioned withdrawal. Disabling a preference cancels pending work and disables affected schedules. Re-enabling a preference alone does not resume them. Optional choices do not suppress verification/recovery or explicitly requested invitation/outcome email.
- PostgreSQL retains next occurrence and ready runs; scalar-ID Redis jobs can be reconstructed after queue loss. Materialization deduplicates and advances atomically. Missed weekly occurrences coalesce into one run. Current membership, verification, subject version, preference/consent and pilot entitlement are checked before materialization and consumption. The consumption callback appends durable intent transactionally; the subsequent M5-04 consumer is still required.
- Weekly local time is retained across DST. Missing spring times skip a week; repeated fall times use their first instant once. Unit cases include US and Australian DST, a half-hour transition, Kathmandu's quarter-hour offset and UTC.
- Forced RLS isolates every person's settings, schedules and runs from other members/tenants/capability contexts. Internal predicate execution is denied to runtime/public callers. Consent events have no runtime update/delete policy. Existing runtime role remains restricted.
- Desktop controls at `/automation`, linked from the selected workspace, reuse FoundationShell, ContentSection, FormField, SelectField, ActionGroup, shared Button, validation focus and ConfirmationDialog. Preferences and saved-schedule presentation are separate feature components; no new CSS system is added. Consent withdrawal uses modal confirmation with Cancel focus/Escape/trigger restoration, accessible errors and duplicate-submission guards. The page explains that scheduled email is not available yet.

## Verification

| Gate | Result / evidence |
|---|---|
| Focused PostgreSQL scheduling | **11 tests / 186 assertions** pass, including on the final image after rollback/reapply; [focused final log](acceptance-after-reapply.txt). Includes actual Redis clear/replay and real PostgreSQL lock waits for both consent/execution race orders. |
| Full PostgreSQL acceptance | **74 tests / 1,617 assertions**; [log](acceptance-full.txt). |
| PostgreSQL integration | **29 tests / 528 assertions**, [log](integration.txt). |
| PHP unit/feature/identity | **30 tests / 265 assertions**, including nine deterministic timezone/DST cases; [log](php.txt). |
| Migration/catalog/schema | Fresh synthetic migration and runtime bootstrap [pass](migration.txt); forced-RLS/function privilege/composite relationship [catalog checks](catalog.txt) and [standalone DDL](schema.txt) pass. [Rollback/reapply](rollback-reapply.txt) preserves **7 reminders, 107 pipeline entries and 70 notification records**; the four new scheduling tables are intentionally discarded/recreated. Focused acceptance passes again. |
| Frontend | Pinned formatting **six supported files**, types, **51 component tests / 13 files**, and production build pass; [commands/results](frontend.txt). |
| Desktop Chromium | **Three journeys pass**: new preferences/scheduling plus both existing workspace/authentication journeys. New flow scans at 1440px and 1920px and the confirmation modal report zero Axe violations with no rules disabled; keyboard timezone selection, Cancel focus, Escape and trigger restoration pass. [Log](browser.txt). |
| Source/image | Final app/migrate/acceptance/worker/scheduler [images built](docker-build.txt); [source scan and positive control](secrets.txt) pass. Application source, Vue page/component, SQL and acceptance-test hashes match the running app image. [Final source hashes](source-sha256.json) record the verified working tree. |

Initial schedule acceptance failed because a helper used PHPUnit's reserved final `run` method; it was renamed. The next run exposed a rejected request leaving a default preference row behind because the request pipeline rendered validation within the workspace transaction. Service mutations now have savepoint boundaries; rejected creates/updates roll back their changes, including intent cancellations and consent events. The new regression verifies the default remains absent after rejection and an entitlement-rejected update retains the original schedule and ready run. The initial [reserved-method failure log](acceptance-initial.txt) is retained. Final service savepoints and all regressions are exercised in the complete and after-reapply logs. The first browser run needed normal sandbox escalation; the next reached both clean desktop scans but used a dialog selector missing the shared title's `?`. The selector is corrected and the final complete journey passes. Docker/Chromium/scanner subprocess restrictions required ordinary approved execution outside the workspace sandbox.

## Desktop evidence

[1440px preferences and schedule](automation-1440.png) and [1920px preferences and schedule](automation-1920.png) were visually inspected: established palette, typography, cards and controls; linked labels; legible status/timezone copy; no horizontal overflow. These do not certify exact UI-05 reference acceptance or small-screen rendering.

## Reproduction and environment

Compose project `weavdash`, local `http://localhost:8000`, PHP 8.5.11, PostgreSQL 16, Redis and array mail. Synthetic backend database: `weavdash_m5_schedules_20261026`; runtime role `weavdash_app`, separate migration/control role. No production/external messages, real-owner provisioning or existing-data cleanup occurred. Main local app has only the additive scheduling migration; rebuilt app/worker/scheduler are running.

From the repository root:

```sh
docker compose --env-file src/.env -p weavdash build app migrate acceptance worker scheduler
docker compose --env-file src/.env -p weavdash exec -T postgres createdb -U postgres -O weavdash_migrator weavdash_m5_schedules_20261026
docker compose --env-file src/.env -p weavdash run --rm -T --no-deps -e DB_DATABASE=weavdash_m5_schedules_20261026 migrate
docker compose --env-file src/.env -p weavdash run --rm -T --no-deps -e DB_DATABASE=weavdash_m5_schedules_20261026 migrate php scripts/grant-runtime.php
docker compose --env-file src/.env -p weavdash run --rm -T --no-deps -e DB_DATABASE=weavdash_m5_schedules_20261026 acceptance
docker compose --env-file src/.env -p weavdash run --rm -T --no-deps -e DB_DATABASE=weavdash_m5_schedules_20261026 app vendor/bin/phpunit -c phpunit.pgsql.xml
docker compose --env-file src/.env -p weavdash run --rm -T --no-deps app php artisan test --compact
docker compose --env-file src/.env -p weavdash exec -T postgres psql -U postgres -d weavdash_m5_schedules_20261026 -v ON_ERROR_STOP=1 < src/tests/Fixtures/schedule-schema-check.sql
python3 src/scripts/check-postgres-schema.py
python3 src/scripts/check-secrets.py
```

Create the synthetic database only once. The rollback rehearsal uses only this database: `migrate:rollback --step=1 --force`, migrate, runtime bootstrap, then the focused acceptance suite. Never rehearse rollback on the main/shared database. Preliminary focused tests mounted the current tests/service read-only while developing; final acceptance uses the rebuilt image.

From `src/`:

```sh
vendor/bin/pint --dirty
npm run format:changed
npm run format:check:changed
npm run typecheck
npm test
npm run build
COMPOSE_PROJECT_NAME=weavdash npm run test:browser -- automation.spec.ts foundation.spec.ts --project=desktop --output=/tmp/weavdash-schedules-browser-final --reporter=list
```

## Handoff

**Next backend slice: M5-04 reminder and digest/review notifications**, consuming ready runs into the durable outbox with current-basis checks again at send reservation. Preserve the foundation's privacy, versioning and no-ambiguous-resend semantics. Follow-up drafts/approval/reply cancellation, numerical budgets, reconciliation operations and provider/restart/outage evidence remain M5-05–M5-10. There is no scheduled email consumer in M5-03.

**Claude:** small-screen testing/refinement, including the preferences and scheduling screen, remains pending; no mobile pass is claimed. Administration, UI-05 exact reference/deviation acceptance, M5-09 recipient-controlled provider delivery, final-candidate remote CI/clean setup/Linux WebKit, manual accessibility/native Safari/full zoom and production readiness remain open. M5/M6 are incomplete.
