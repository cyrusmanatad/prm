# M5-06 logged-reply cancellation — 2026-10-31

Status: **implemented; backend/desktop locally verified; small-screen acceptance pending**. Working tree based on `fb67cfb` on `m5/onboarding-lifecycle-email`. See the [contract](../../../m5-logged-reply-contract.md). M5/M6 remain incomplete. D28 retains Claude's administration/small-screen scope.

## Delivered behavior

- **Reply received** is an explicit activity type in the existing lead drawer. Shared `confirmAction`/`ConfirmationDialog` identifies the lead, cancellation consequence, unaffected reminders and downloaded-file limitation. The existing SelectField, activity fields, controls, validation and drawer layout are reused. No CSS system is introduced.
- A reply atomically clears the lead next-action date, disables existing follow-up schedules, cancels ready runs and marks draft/approved follow-ups cancelled with cleared approvals and incremented versions. Cancellation crosses private owners only within the selected workspace, without projecting their content. Completed run provenance and historical activities/drafts are retained. Ordinary email/call/chat/note logging does not cancel work.
- Cancelled drafts cannot be edited, approved or downloaded. Old exact-version links and queued Redis replays stop. Fresh explicitly created work is allowed after a reply; separate reminders, other leads and transactional notices remain active. Unsaved lead notes survive the reply; the drawer clears its next-action field so a later save does not silently restore the old date.
- The security-definer trigger is write-only, has a fixed search path, checks actor/workspace/editing membership, and has no runtime/PUBLIC execute grant. Existing RLS read policies remain unchanged. The workspace advisory lock serializes replies with preparation and approved downloads; already downloaded messages cannot be recalled. No follow-up email transport is enabled.

## Verification

| Gate | Result / evidence |
|---|---|
| Full PostgreSQL/Redis acceptance | **97 tests / 2,134 assertions pass**, [log](acceptance-full.txt). Includes all six new reply checks: exact-version cancellation, real Redis queued/replayed preparation, cross-owner privacy, completed-run history, unaffected reminders/other activity, fresh work, validation/stale/viewer/revocation/foreign-tenant denial, atomic rollback, inaccessible trigger execution and both real PostgreSQL lock orderings for preparation and approved download. |
| Synthetic migration/rollback | [Fresh migrations/runtime grants](migration.txt) pass. [Rollback/reapply](rollback-reapply.txt) preserves **151 leads, 183 activities, 47 drafts, 173 schedules, 162 runs and 1,494 audit rows**, [before](preserved-before.txt), [after](preserved-after.txt). Rollback converts cancelled drafts to discarded and retains reply history; it restores no consent, approval or due date. **6 reply tests / 127 assertions pass after reapply**, [log](acceptance-after-reapply.txt). The separate database is `weavdash_m5_reply_20261031`; no shared-database rollback or deletion occurred. |
| PostgreSQL integration | **29 tests / 528 assertions pass**, [log](integration.txt). |
| PHP unit/feature | **30 tests / 265 assertions pass**, [log](php.txt). |
| Frontend/PHP quality | Pinned Prettier checks **4 supported files**, [format](format.txt). [Types](types.txt), [host build](build.txt), [Pint](pint.txt), [image build](docker-build.txt) and **51 component tests / 13 files**, [log](components.txt), pass. The schema checker includes the additive SQL and nonexecutable trigger check; [schema log](schema.txt) passes and does not substitute for runtime RLS acceptance. |
| Desktop Chromium | **4 journeys pass**, [log](browser.txt): confirmed reply/cancellation history/stale download, existing manual follow-up workflow, pipeline mutations/stale tabs/unsaved edits, and viewer/revocation access. The new journey has zero Axe violations in confirmation and at 1440px/1920px inbox widths, no overflow, initial Cancel focus, Escape/trigger restoration, and no mutation after cancel. |
| Running app | Eight selected product files match host source, [comparison](source-match.txt). The local app/worker/scheduler are rebuilt and running; the main development database receives only the additive migration. Host and image frontend builds produce different manifests; hashes of independently generated manifests are not used as source-equivalence evidence. Browser evidence exercises the image assets, whose source files match. The acceptance image additionally includes the corrected test harness and updated schema-check script. |
| Source scan | [Log](secrets.txt): positive control passes; the verified pre-existing evidence-checksum false positive at `planning/evidence/m5/schedules/source-sha256.json:7` remains. This is not a clean source-scan gate; no rule was weakened or suppressed. |

Initial focused runs exposed two test-harness mistakes: nesting tenant context around the real consumer, then a missing DB import in its transaction observer. Both are corrected; the real consumer is now observed after its completion write and before commit. The [intermediate recheck](acceptance-harness-recheck.txt) retains the missing-import failure. The full suite and post-reapply run above pass without weakened lock/timeout assertions. Initial sandbox restrictions on Docker/Git/scanner subprocesses were resolved through command escalation.

## Inspected desktop screenshots

[Reply confirmation at 1440px](reply-confirmation-1440.png), [cancelled inbox at 1440px](reply-inbox-1440.png) and [1920px](reply-inbox-1920.png) were visually inspected for shared palette, typography, spacing, field labels, focus/control presentation and readable consequences/history. Small-screen acceptance and broader UI-05 reference/deviation acceptance remain open.

## Reproduction and handoff

Local Compose project `weavdash`, `http://localhost:8000`, PHP 8.5.11/PostgreSQL 16/Redis, array mail. Credentials and email transport are unchanged. Use the existing synthetic M5-06 database; create it only once. From repository root:

```sh
docker compose --env-file src/.env -p weavdash build app migrate acceptance worker scheduler
docker compose --env-file src/.env -p weavdash exec -T postgres createdb -U postgres -O weavdash_migrator weavdash_m5_reply_20261031
docker compose --env-file src/.env -p weavdash run --rm -T --no-deps -e DB_DATABASE=weavdash_m5_reply_20261031 migrate
docker compose --env-file src/.env -p weavdash run --rm -T --no-deps -e DB_DATABASE=weavdash_m5_reply_20261031 migrate php scripts/grant-runtime.php
docker compose --env-file src/.env -p weavdash run --rm -T --no-deps -e DB_DATABASE=weavdash_m5_reply_20261031 acceptance
docker compose --env-file src/.env -p weavdash run --rm -T --no-deps -e DB_DATABASE=weavdash_m5_reply_20261031 app vendor/bin/phpunit -c phpunit.pgsql.xml
docker compose --env-file src/.env -p weavdash run --rm -T --no-deps app php artisan test --compact
python3 src/scripts/check-postgres-schema.py
python3 src/scripts/check-secrets.py
```

Rollback only in the synthetic database using the migration service with `DB_DATABASE=weavdash_m5_reply_20261031`, `php artisan migrate:rollback --step=1 --force`; reapply, compare history counts and rerun `LoggedReplyTest`. Never run this rehearsal on the shared development database. Source scanning requires Docker access. From `src/`:

```sh
vendor/bin/pint --test --dirty
npm run format:check:changed
npm run typecheck
npm test
npm run build
COMPOSE_PROJECT_NAME=weavdash npm run test:browser -- logged-reply.spec.ts pipeline.spec.ts follow-up-drafts.spec.ts --grep-invert 'scheduled preparation' --project=desktop --reporter=list --output=/tmp/weavdash-m5-reply-browser
```

**Next: M5-07 general approval-basis invalidation/reconciliation and broader concurrency acceptance.** Preserve reply terminal states, private ownership and lock ordering. M5-08 retains approved application-sent outreach/review email, rate budgets and operator reconciliation; provider receipt is M5-09 and broad recovery acceptance M5-10. Administration, small-screen, UI-05, current-candidate remote CI/clean setup/WebKit and inherited screen-reader/full-zoom/native Safari/production gates remain open.
