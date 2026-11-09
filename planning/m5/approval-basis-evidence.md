# M5-07 approval invalidation verification — 2026-11-09

Status: **backend and scoped Chromium desktop/mobile regression locally verified**. Branch `milestone/continue-2026-11-09`, working tree based on `a89963c`. See the [contract](approval-basis-contract.md). M5/M6 remain incomplete; D28's broader small-screen/administration and inherited release gates remain open.

## Scope and results

Approved follow-ups now lose approval atomically when their lead, schedule/run, consent/preferences, membership, identity or entitlement basis changes. Content edits require fresh approval. Time-based expiry is reconciled by `automation:drafts`, with bounded, repeatable, content-free maintenance and audit events. Existing forced RLS, private ownership, manual sending, reply cancellation and Professional renewal semantics are retained. No product markup or CSS changed; the inbox/composer continue using FoundationShell, ContentSection, FormField, SelectField, ActionGroup, Button and ConfirmationDialog.

| Gate | Result |
| --- | --- |
| Fresh PostgreSQL migration/runtime grants | Passed in separate synthetic `weavdash_m5_approval_20261109`; no shared/staging migration. |
| Full PostgreSQL/Redis acceptance | **122 tests / 2,708 assertions passed**. Includes seven new approval tests, cross-owner/workspace/privacy denial, rollback, exact recipient/content invalidation, membership deletion/reinsertion, consent/identity restoration, actual clock expiry, idempotent reconciliation and real PostgreSQL blocking in both lead-edit/handoff and consent-withdrawal/handoff orders. Existing reply, SMTP, queue/restart, Professional and role gates pass. |
| PostgreSQL integration | **35 tests / 635 assertions passed**. The exact Inertia workspace projection expectation now includes the already implemented `experience` field; foreign-workspace exclusion stays strict. |
| PHP unit/feature | **42 tests / 362 assertions passed**. |
| Migration rollback/reapply | Passed; retained **405 leads, 188 drafts, 222 schedules, 209 runs and 3,067 audit rows**. Rollback restores no obsolete approval. **7 approval tests / 241 assertions passed after reapply.** |
| PHP style | `vendor/bin/pint --dirty` and `vendor/bin/pint --test --dirty` passed. |
| Frontend | Pinned Prettier check covers the one added browser spec; `npm run typecheck`, **75 component tests / 17 files**, and `npm run build` passed. No production frontend source changed. |
| Chromium journeys | New invalidation journey passed at **1440×1080 and 390×1080**. Existing manual approval/download/edit/discard and confirmed-reply journeys passed at desktop widths **1440/1920×1080**. Existing mobile variants of those two journeys remain skipped under D28; this narrow mobile smoke check does not close general small-screen acceptance. |
| Accessibility/rendering | New journey: zero Axe violations, no horizontal overflow, initial Cancel focus, retained discarded content and removed obsolete approve/download controls. Inspected temporary captures at 1440×1080 and 390×1080: existing burgundy/cream palette, card spacing, readable controls and single-column mobile flow remain consistent. Temporary images/traces removed; the new spec does not routinely capture screenshots. |
| Source scan | Positive control passed. Scan remains **not clean**: unchanged SHA-256 evidence entries in `planning/evidence/m5/schedules/source-sha256.json:7` and `planning/evidence/pipeline-drag/source-sha256.json:10` match the generic-key rule. Both were inspected and are pre-existing content hashes. No scanner rule/suppression was changed. |
| Documentation | **421 local file links across changed Markdown** and `git diff --check` passed after updating the indexes, status, roadmap, register and handoff. |

The first new test run exposed an invalidation scope-query error; it was corrected and the final full suite above passes. The preference fixture now uses its actual version sequence; the new browser journey waits for workspace creation. Broader regressions found a pre-existing notification fixture lock inversion after Professional write guards were introduced: the fixture now takes the same workspace-before-row lock as reservation, retaining a real PostgreSQL blocking assertion and no-repeat-send check. Probe diagnostics assert valid final JSON and include process output when malformed. Professional entitlement expiry retains its existing cancelled state.

## Reproduction

Docker commands run from the repository root. Existing source-mounted Compose project `weavdash-professional` supplies PostgreSQL 16, Redis and PHP 8.5.11; `/tmp/weavdash-pipeline-compose.yaml` mounts this checkout. Use the separate synthetic database, never substitute the shared database for rollback. Mail is array/loopback SMTP only; no external message or deployment occurred.

```sh
docker compose --env-file src/.env -p weavdash-professional -f compose.yaml -f /tmp/weavdash-pipeline-compose.yaml run --rm -T --no-deps -e DB_DATABASE=weavdash_m5_approval_20261109 migrate
docker compose --env-file src/.env -p weavdash-professional -f compose.yaml -f /tmp/weavdash-pipeline-compose.yaml run --rm -T --no-deps -e DB_DATABASE=weavdash_m5_approval_20261109 migrate php scripts/grant-runtime.php
docker compose --env-file src/.env -p weavdash-professional -f compose.yaml -f /tmp/weavdash-pipeline-compose.yaml run --rm -T --no-deps -e DB_DATABASE=weavdash_m5_approval_20261109 acceptance vendor/bin/phpunit -c phpunit.acceptance.xml
docker compose --env-file src/.env -p weavdash-professional -f compose.yaml -f /tmp/weavdash-pipeline-compose.yaml run --rm -T --no-deps -e DB_DATABASE=weavdash_m5_approval_20261109 acceptance vendor/bin/phpunit -c phpunit.pgsql.xml
```

For rollback, use that migration service/database with `php artisan migrate:rollback --step=1 --force`, then reapply and rerun `--filter FollowUpApprovalTest`. The standalone single-user schema checker was not extended/run; fresh real PostgreSQL installation, RLS acceptance and function-execution denial supply this slice's schema/runtime evidence.

The temporary browser app uses `/tmp/weavdash-approval-compose.yaml`, Compose project `weavdash-m5-approval`, port `127.0.0.1:8001`, the same synthetic database and source mount, with separate session origins and array mail. Its app container is stopped after verification; the shared app on port 8000 and staging remain unchanged. To repeat the test, start that temporary app and run from `src/`:

```sh
npm run format:check:changed
npm run typecheck
npm test
npm run build
COMPOSE_PROJECT_NAME=weavdash-m5-approval PLAYWRIGHT_BASE_URL=http://127.0.0.1:8001 npx playwright test approval-basis.spec.ts follow-up-drafts.spec.ts logged-reply.spec.ts --grep-invert 'scheduled preparation' --project=desktop --project=mobile --reporter=list --output=/tmp/weavdash-m5-approval-browser
```

The temporary Compose overrides are environment aids, not committed deployment configuration. Before running updated dispatchers against any other local database, apply the new additive migration there through the migration role. Do not apply to staging/production without separate deployment authorization.

## Next step and remaining gates

**M5-08 is next:** settle operator permissions and uncertain-send reconciliation/resend boundaries, approved application-sent outreach and review-notice recipient/consent/content, transport budgets and fairness/backoff; then implement with the current-basis locks and exact-version approval preserved. Do not treat a prior approval or a restored subscription as fresh send authorization. M5-09 provider receipt, M5-10 broader recovery, administration, UI-05, general small-screen/manual accessibility/native Safari/WebKit, current-candidate remote CI/clean setup and inherited production/commercial gates remain open.
