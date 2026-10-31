# M5-05 private follow-up drafts and approval inbox — 2026-10-31

Status: **implemented manual/private preparation path; backend/desktop locally verified; small-screen acceptance pending**. Working tree based on `855167c` on `m5/onboarding-lifecycle-email`. See the [contract](../../../m5-follow-up-drafts-contract.md). D28 retains Claude's administration/small-screen scope. M5/M6 remain incomplete.

## Delivered behavior

- Explicitly written private plain-text drafts and generic scheduled seeds require no AI or mailbox integration. Actor/tenant forced RLS excludes other workspace administrators, viewers, revoked members, foreign workspaces and capability contexts. Scheduled seeds have no recipient, brand name or private CRM interpolation.
- Save/edit, exact-version approval, guarded approved-message download and confirmed discard form the complete manual path. Recipient/subject header newlines are rejected. Edits remove approval; stale versions fail. Approval/download recheck verified editing access, pilot entitlement, active lead version and scheduled consent/preferences/run/rule versions. The download is a private, noncached text attachment for the owner's own email client. No follow-up outbox intent or email is produced; no receipt is claimed.
- `automation:drafts` reconstructs ready follow-up run IDs from PostgreSQL and queues scalar-ID jobs. Draft/audit creation and run completion are atomic. Completed-run checks and unique provenance prevent replay duplicates. Consent withdrawal cancels pending preparation and blocks obsolete scheduled handoff.
- ApprovalInbox and FollowUpComposer reuse shared sections, fields, buttons, action groups, validation focus and confirmation dialogs. Existing ReminderSchedule is extended for the shared subject/date/consent presentation. No new CSS system is added. Inbox history is bounded to the latest 50 entries; lead selectors show the newest 100 active leads.

Evidence text logs have trailing whitespace and blank end lines trimmed for Git; results and diagnostic content are retained.

## Verification

| Gate | Result / evidence |
|---|---|
| New PostgreSQL suite | **5 tests / 89 assertions pass**, including after synthetic rollback/reapply: [initial](acceptance-initial.txt), [after reapply](acceptance-after-reapply.txt). Covers actor/tenant/viewer/revocation/capability isolation, header validation, exact-version/manual handoff, edit invalidation/discard, current lead/verification/entitlement, durable redispatch, transaction rollback, replay, blank-recipient review and consent withdrawal. Redis dispatch reconstruction is deterministic here; real queued materialization/preparation is exercised in the browser journey. |
| Full acceptance | [Broad run](acceptance-full.txt): **90 of 91 tests pass**, with one existing WorkerCrashTest timing-barrier failure during concurrent verification. The unchanged failure passes alone: **1 test / 18 assertions**, [recheck](worker-recheck.txt). All 91 distinct tests have passing coverage across those runs; the broad invocation itself is not a clean pass. No barrier or timeout assertion was weakened. |
| PostgreSQL integration | **29 tests / 528 assertions pass**, [log](integration.txt). |
| PHP unit/feature | **30 tests / 265 assertions pass**, [log](php.txt). |
| Migration/schema | Fresh migration and runtime grants: [log](migration.txt). Synthetic-only [rollback/reapply](rollback-reapply.txt) preserves **121 pipeline entries, 75 schedules, 71 runs and 105 notifications**, [before](preserved-before.txt), [after](preserved-after.txt). Reapply/new suite pass. Host [DDL/RLS catalog check](schema.txt) passes; runtime RLS evidence is the acceptance suite. Main development database receives only the additive migration, [log](main-migration.txt). |
| Frontend | Pinned Prettier checks **7 supported files**, [format](format.txt); [types](types.txt), host [build](frontend-build.txt), [Pint](pint.txt) and final [image build](docker-build-final.txt) pass. Existing component suite: **51 tests / 13 files pass** (`npm test`, terminal output captured during this session before final copy corrections). |
| Desktop Chromium | Four distinct journeys pass across [broad run](browser.txt) (existing automation/reminder delivery) and [corrected follow-up run](browser-final.txt) (manual approval/download/edit/discard and real Redis scheduled preparation/required-recipient recovery). New manual flow has zero Axe violations at 1440px/1920px, no overflow, Cancel initial focus, Escape/trigger restoration and modal accessibility checks. Final copy/screenshot refresh is [recorded here](browser-screenshot-refresh.txt). |
| Running source | Host and running app match all 14 selected M5-05 product files, [comparison](source-match.txt). Browser tests run from the host; their final copy assertion/formatting is newer than the image test copy. |
| Source scan | [Log](secrets.txt): positive control passes; only the pre-existing evidence SHA-256 false positive on `planning/evidence/m5/schedules/source-sha256.json:7` remains. This is the previously verified content checksum described in [M5-04 evidence](../scheduled-delivery/README.md), not a clean source-scan gate. No scanner rule was changed or suppressed. |

Initial browser launch could not write inherited `src/test-results` artifacts owned by another user: [log](browser-initial.txt). Checks use fresh `/tmp` output and list reporter. The broad browser run passed the two existing journeys but new tests needed a navigation wait and a locator scoped to the dialog's retained server error. Both corrected journeys pass. Screenshot inspection also caught stale wrapped availability text; it is corrected in the final source/image and screenshot refresh. These corrections retain the server-error and confirmation behavior.

## Desktop screenshots

[1440px](follow-up-drafts-1440.png) and [1920px](follow-up-drafts-1920.png) are retained and visually inspected for shared palette, typography, spacing, cards/controls, labels, review text and manual sending status. Small-screen acceptance remains with Claude. These screenshots do not close UI-05 reference/deviation acceptance.

## Reproduction and handoff

Compose project `weavdash`, local `http://localhost:8000`, PHP 8.5.11/PostgreSQL 16/Redis, array mail. Separate synthetic backend database `weavdash_m5_drafts_20261031`; no databases or existing records removed. Main app has the additive migration. App/worker/scheduler are rebuilt and restored at handoff; credentials and email transport remain unchanged.

From repository root:

```sh
docker compose --env-file src/.env -p weavdash build app migrate acceptance worker scheduler
docker compose --env-file src/.env -p weavdash exec -T postgres createdb -U postgres -O weavdash_migrator weavdash_m5_drafts_20261031
docker compose --env-file src/.env -p weavdash run --rm -T --no-deps -e DB_DATABASE=weavdash_m5_drafts_20261031 migrate
docker compose --env-file src/.env -p weavdash run --rm -T --no-deps -e DB_DATABASE=weavdash_m5_drafts_20261031 migrate php scripts/grant-runtime.php
docker compose --env-file src/.env -p weavdash run --rm -T --no-deps -e DB_DATABASE=weavdash_m5_drafts_20261031 acceptance
docker compose --env-file src/.env -p weavdash run --rm -T --no-deps -e DB_DATABASE=weavdash_m5_drafts_20261031 app vendor/bin/phpunit -c phpunit.pgsql.xml
docker compose --env-file src/.env -p weavdash run --rm -T --no-deps app php artisan test --compact
python3 src/scripts/check-postgres-schema.py
python3 src/scripts/check-secrets.py
```

Create the database only once. Rehearse rollback only there: `migrate php artisan migrate:rollback --step=1 --force`, reapply, restore runtime grants, compare retained counts and rerun FollowUpDraftTest. Rollback removes draft storage; it does not restore removed drafts on reapply.

From `src/`:

```sh
vendor/bin/pint --test --dirty
npm run format:check:changed
npm run typecheck
npm test
npm run build
COMPOSE_PROJECT_NAME=weavdash npm run test:browser -- follow-up-drafts.spec.ts automation.spec.ts scheduled-delivery.spec.ts --project=desktop --reporter=list --output=/tmp/weavdash-m5-drafts-browser
```

Pause local worker/scheduler for deterministic browser queue processing and restore afterward. The existing migration fixture matures only guarded synthetic `m1-browser-scheduled-*` identities. Runtime commands process real Redis jobs; no external mail is sent. Run process-crash probes separately from heavy browser/build work on this shared host.

**Next: M5-06 manually logged reply cancellation**, preserving private draft ownership, current-basis locks and durable run provenance. M5-07 completes invalidation/reconciliation/race acceptance. M5-08 retains required application-sent approved outreach/review-notice contract and delivery, rate budgets and operator reconciliation. Manual handoff does not satisfy that email gate. M5-09 provider receipt and M5-10 broader recovery remain open, together with administration, UI-05, small-screen, current-candidate CI/clean setup/WebKit and inherited manual accessibility/Safari/full-zoom/production gates.
