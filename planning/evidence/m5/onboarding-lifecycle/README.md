# M5-02 onboarding lifecycle email — 2026-10-26

Status: **backend and desktop implementation locally verified; small-screen/provider acceptance pending**. Branch `m5/onboarding-lifecycle-email`, based on `4f90c3e`; implementation and evidence are checkpointed in the accompanying M5-02 commit. See the [recipient/access contract](../../../m5-onboarding-lifecycle-contract.md). D28 assigns administration and small-screen testing/refinements to Claude and authorizes this backend continuation with UI-05 acceptance carried forward.

## Delivered behavior

- Opt-in submission alerts notify only the writer who selected the option for this invitation generation. The public form cannot nominate recipients. Replacement resets the option unless selected again; no existing requests are backfilled.
- Correction/acceptance email requires explicit selection and an entered address. There is no inferred CRM/business-email recipient. Versioned outcomes and encrypted intent commit together; mail excludes private field values, notes, correction text, documents and finances.
- New single-use return links work in a fresh browser after the original 24-hour session expires, within the original seven-day invitation lifetime. GET does not consume the link. Replacement, revocation, stale outcome/version, expiry and lost writer authorization deny unused links. Acceptance remains read-only. Request expiry requires explicit invitation replacement before outcome email can be requested.
- Durable outbox/Redis jobs retain scalar identifiers, recheck current authorization and state, cancel obsolete notices, and preserve conservative no-replay handling after ambiguous sending. Capture and SMTP acceptance never claim mailbox receipt.
- Existing desktop forms reuse `OnboardingEmailFields`, `FormField`, `ContentSection`, shared Button and FoundationShell. New `OnboardingSubmissionNotice` shares the opt-in presentation across creation/replacement. History identifies the lifecycle kind. Desktop inspection prompted accurate read-only guidance on submitted/accepted forms. Existing confirmation flows and responsive styling remain intact.

## Verification

| Gate | Result / evidence |
|---|---|
| Final PostgreSQL acceptance | **63 tests / 1,431 assertions**, [log](acceptance-full-final.txt). Includes existing role, concurrency, queue/process, finance, invitation and onboarding regressions. |
| Focused lifecycle acceptance | **10 tests / 289 assertions**, including real runtime-role/RLS denial, function privilege/catalog checks, explicit recipients, stale/version/generation checks, token replay/expiry, rollback, Redis loss/replay and uncertain delivery. [Initial passing run](acceptance.txt), [after rollback/reapply](acceptance-after-reapply.txt). |
| PostgreSQL integration | **29 tests / 528 assertions**, [log](integration.txt). |
| Identity/unit/feature regression | **29 tests / 256 assertions**, [log](php.txt). |
| Migration and schema | Fresh migration passes. [Rollback/reapply](migration.txt) preserves all **139** synthetic onboarding requests; lifecycle notification history/return links are deliberately discarded by rollback. [Standalone DDL check](schema.txt) and [real PostgreSQL catalog checks](catalog.txt) pass. Standalone mode alone is not RLS evidence. |
| Frontend | Six changed supported frontend files pass pinned formatting; type checking, **51 component tests in 13 files**, and [production build](build.txt) pass. Final changed-file PHP Pint and Git whitespace checks pass. |
| Desktop browsers | Four distinct onboarding journeys pass across the retained [three regression passes](browser.txt) and [corrected lifecycle follow-up](browser-followup.txt), then the [final rebuilt-app run](browser-final.txt). Chromium desktop only. Lifecycle keyboard/Axe/overflow checks cover 1440px and 1920px; no Axe rules disabled. |
| Image/source | App/migrate/acceptance/worker/scheduler [images built](docker-build.txt). [Source scanner and positive control](secrets.txt) pass. Source fingerprints are recorded in [source hashes](source-sha256.json). |

The first acceptance attempt had a missing required synthetic lead value; the next exposed a nullable test-helper return type. Both fixtures were corrected. After reapply, denial tests were adjusted to recognize PostgreSQL table-privilege rejection as well as zero rows under RLS after the existing blanket bootstrap grants. No runtime permission was broadened to satisfy a test. The first desktop lifecycle run used the wrong consent label; it timed out and the selector was corrected. The legacy standalone schema fixture expected the replaced generation constraint; it now checks both partial unique indexes, return-link RLS/composite targeting and all five restricted functions. Corrected runs above pass. Sandbox subprocess/Docker restrictions required ordinary approved execution outside the sandbox. Retained text logs have ANSI escapes/trailing whitespace normalized without changing results.

## Desktop evidence

- [1440px outcome controls](outcome-review-1440.png) and [1920px outcome controls](outcome-review-1920.png): linked labels, explicit recipient, visible keyboard focus, established typography/palette, no document overflow.
- [Accepted return page](accepted-return-desktop.png): fresh bearer-link access, read-only controls and corrected guidance.
- [Lifecycle delivery history](lifecycle-history-desktop.png): event labels and truthful local-capture/cancellation status.

All four screenshots were visually inspected. Fixtures are disposable synthetic accounts; no external mail was sent. These screenshots do not certify UI-05 exact reference/deviation acceptance or small-screen rendering.

## Reproduction and environment

Compose project `weavdash`, PHP 8.5.11, PostgreSQL 16, Redis and local array mail. Restricted runtime role `weavdash_app`; only migration/acceptance control services receive fixture credentials. Backend acceptance/integration use the separate synthetic database `weavdash_m5_lifecycle_20261026`. Desktop browser fixtures use the local app at `http://localhost:8000`; app, worker and scheduler must be rebuilt together after migration. Worker/scheduler are paused during deterministic browser checks and restored at handoff. The database is retained for reproducibility, without cleanup of existing application data.

From the repository root:

```sh
docker compose --env-file src/.env -p weavdash build app migrate acceptance worker scheduler
docker compose --env-file src/.env -p weavdash run --rm -T --no-deps -e DB_DATABASE=weavdash_m5_lifecycle_20261026 migrate
docker compose --env-file src/.env -p weavdash run --rm -T --no-deps -e DB_DATABASE=weavdash_m5_lifecycle_20261026 migrate php scripts/grant-runtime.php
docker compose --env-file src/.env -p weavdash run --rm -T --no-deps -e DB_DATABASE=weavdash_m5_lifecycle_20261026 -v /home/cyrusadmin/weavdash/src/tests/Acceptance/OnboardingLifecycleTest.php:/var/www/html/tests/Acceptance/OnboardingLifecycleTest.php:ro acceptance vendor/bin/phpunit -c phpunit.acceptance.xml
docker compose --env-file src/.env -p weavdash run --rm -T --no-deps -e DB_DATABASE=weavdash_m5_lifecycle_20261026 app vendor/bin/phpunit -c phpunit.pgsql.xml
docker compose --env-file src/.env -p weavdash run --rm -T --no-deps app php artisan test --compact
docker compose --env-file src/.env -p weavdash exec -T postgres psql -U postgres -d weavdash_m5_lifecycle_20261026 -v ON_ERROR_STOP=1 < src/tests/Fixtures/notification-schema-check.sql
python3 src/scripts/check-postgres-schema.py
python3 src/scripts/check-secrets.py
```

The acceptance file is mounted read-only because the final privilege-denial helper was refined after the image source copy; application code is from the final built image. The full final run includes this exact mounted test revision. Rollback/reapply used only this synthetic database with `migrate:rollback --step=1 --force`, followed by migration; never run the rehearsal against shared/live data.

From `src/`:

```sh
vendor/bin/pint --dirty
npm run format:changed
npm run format:check:changed
npm run typecheck
npm test
npm run build
COMPOSE_PROJECT_NAME=weavdash npm run test:browser -- onboarding-lifecycle.spec.ts onboarding-notifications.spec.ts onboarding.spec.ts --project=desktop --output=/tmp/weavdash-lifecycle-browser-final --reporter=list
```

## Carry-forward and next step

**Next Codex backend task: M5-03**, persisted schedules/timezone/preferences/consent and rule versions, followed by M5-04 reminders/digests and M5-05–M5-10 approval/delivery/recovery work. All remain open in the [register](../../../remaining-tasks.md).

**Claude:** administration implementation ADM-01–ADM-07 and small-screen UI testing, optimization and responsive refinements, including this lifecycle flow. The desktop-specific lifecycle browser test intentionally skips mobile projects; that is delegated work, not a mobile pass. M5-09 actual queued provider receipt/link-opening, UI-05 deviation acceptance, final-candidate remote CI/clean setup/Linux WebKit and inherited manual accessibility/native Safari/full zoom and production readiness remain open. No milestone, provider delivery or launch completion is claimed.
