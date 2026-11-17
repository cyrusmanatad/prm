# Administration follow-up — implementation and verification

Scope: the "ready to do" items B1–B6 of the [administration handoff](implementation-handoff.md#part-b--administration-work-not-yet-implemented). Date: 2026-11-17. Revision: working tree on `administration/part-b`, based on `main` at `748c075`. Environments: two disposable local Compose projects, `weavdash-admin` (existing database, single-process application on `localhost:8010`) and `weavdash-partb` (new database migrated from empty, application with four server workers on `localhost:8011`); PostgreSQL 16, Redis, array mail, no ClamAV service. No shared, staging or production system was touched, no real operator was provisioned and no email was sent.

## What was implemented

| Item | Implemented | Still open |
|---|---|---|
| B3 | The other party's portal labels a suspended workspace's partnership **Paused**, with "This partner's / brand's workspace is currently unavailable. The partnership is kept." `relationships[].paused` on `GET /brand` and `GET /partner` comes from `app_partnership_paused`, which answers only for a participant of that active partnership. No reason, time or operator is disclosed. Ending the partnership stays available. | — |
| B4 | Every minute the scheduler (`automation:dispatch`, `notifications:dispatch`) closes work that authorization would refuse: a ready run, or a schedule falling due, while its workspace or actor is suspended becomes a `skipped` run with an `automation.run.skipped` event in the workspace audit log, and the schedule moves to its next occurrence. Pending email and approved follow-ups of a suspended account are cancelled. | Operators cannot see skipped runs in the console (needs tenant audit browsing, B12). |
| B5 | Joined-date range on Accounts and created-date range on Workspaces (`from`, `to`; UTC days; `to` inclusive), shown as applied filters and restored from the URL. | Fixture/protected classification filters wait for B11. The optional column chooser was not built. |
| B6 | Overview attention items for approved support access ending within two hours and for cleanup batches whose current dry run has blocked records. Figures are null outside the operator's capabilities. | — |

**Gap closed beyond the brief (B4).** Before this change an **account** suspension left that person's pending email and ready scheduled runs waiting, and they would have been sent or run after reactivation, against confirmed rules 5 and 6. Workspace suspension already cancelled them. Both are now closed while suspended and never replayed.

Schema: migration `2026_11_17_000001_add_platform_followup` runs `src/database/sql/platform-followup.sql`. It adds `skipped` to `schedule_runs.state`; adds `app_partnership_paused`, `app_skip_suspended_work`, `app_suspended_due_schedules` and `app_skip_due_schedule`; and replaces `app_platform_accounts`, `app_platform_workspaces` and `app_platform_overview`. Rollback converts `skipped` runs to `cancelled` and restores the three earlier definitions from `platform-console.sql`. Re-run `scripts/grant-runtime.php` after migrating.

Reused and new components: both portals reuse `StatusBadge`. New `features/admin/DateRangeFields.vue` holds the labelled date pair; the Audit page now uses it too instead of its own copy. No new styling.

## B1 — the intermittent administration journey

| Finding | Evidence |
|---|---|
| **One cause found and fixed (test defect).** The first authenticator step sent the code for the *previous* 30-second step, which the server stops accepting when the clock ticks over while the request is in flight. | This is the failure of the `webkit-desktop` job on `main` at `f16fd09`: the journey stayed on `/admin/security` after "Verify and continue". The journey now uses the earlier step only when at least eight seconds of the current step remain. |
| **The mobile-only failures did not reproduce.** | 21 consecutive passes of the journey: mobile 7 of 7 on the multi-worker server, mobile 6 of 6 on the single-process server, and desktop and mobile run at the same time, twice on each server (8 passes). |
| **The journey is sensitive to a slow single-process server.** | Alone it takes about 3.1 minutes with four workers and 4.5 minutes single-process. With a second browser running against the single-process server it took 5.4 to 7.8 minutes, close to its 8-minute limit; with four workers, 3.7 to 4.5 minutes. |

The cause of the three original mobile failures is therefore **not established**. They are consistent with a stalled single-process server on a busy host, and nothing pointed to a session race: no unexpected sign-out or sign-in page occurred in any run. **Proposal:** run browser gates against a multi-worker server (`PHP_CLI_SERVER_WORKERS=4`); this was not changed in `compose.yaml`.

## Commands and results

| Gate | Command (from `src/` unless stated) | Result |
|---|---|---|
| Formatting | `npm run format:check:changed` | Pass, 12 changed frontend files |
| Types | `npm run typecheck` | Pass |
| Component tests | `npm test` | Pass, 23 files / 112 tests (4 new) |
| Build | `npm run build` | Pass |
| PHP style, default suite | `vendor/bin/pint --test`; `php artisan test --compact` | Pass, 204 files; pass, 42 tests / 362 assertions |
| Fresh migration, rollback and re-apply | `migrate` from empty; `migrate:rollback --step=1` twice; `migrate` | Pass; definitions and the state constraint verified after each step |
| RLS script (B2) | `rls-fixtures.sql`, then `rls.sql` on the fresh database | Pass, `RLS_CHECKS_PASSED`, including the new platform section |
| PostgreSQL integration (B2) | `phpunit -c phpunit.pgsql.xml` on the fresh database | Pass, 35 tests / 635 assertions |
| Administration acceptance | `phpunit -c phpunit.acceptance.xml --filter 'PlatformGovernanceTest\|PlatformConsoleTest'` | Pass, 16 tests / 582 assertions (3 new tests) |
| Schema DDL check | `python3 scripts/check-postgres-schema.py` | Pass |
| Related acceptance | same, filter on console, schedules, scheduled notices, follow-ups, partnerships, outbox, professional and delivery classes | Pass (91 tests with the governance class) |
| Full acceptance (PostgreSQL/Redis) | `docker compose … run --rm acceptance` on the fresh stack | 160 tests, 3,781 assertions: 155 pass, 3 skipped, **2 fail for the known environment reasons** (below) |
| Browser, administration journey | `npx playwright test administration.spec.ts --project=desktop` / `--project=mobile` | Pass in all 21 runs (see B1) |
| Browser, affected journeys | portals, professional, professional UI, partner navigation, accessibility, adversarial and delivery operations specs, desktop and mobile | 28 runs on the multi-worker server: 25 pass, 3 fail in two tests that proved timing-sensitive (below). After the fix both pass 2 of 2 on desktop and mobile there, and they pass on the single-process server |
| CI on `main` (B2) | GitHub Actions at `748c075` | Backend and frontend workflows pass, including the administration journey in the desktop, mobile, `webkit-desktop` and `webkit-mobile` jobs (44 tests each) |

**Two browser tests assumed a single-process server.** `professional-ui.spec.ts` (partnership panel) opened the next page without waiting for the acceptance request, so a second worker could answer first and show no partnership; it failed on desktop and mobile with four workers and passed single-process. The test now waits for the acceptance redirect. `adversarial.spec.ts` (five roles) failed once on desktop with `page.reload: net::ERR_ABORTED` and passed in six later runs; it was not changed. Neither involves this change's code, and both are a caution for the multi-worker proposal above: other journeys may hold the same assumption and the full browser suite has not been run with several workers.

The two full-suite failures are unchanged from the [console evidence](console-evidence.md#commands-and-results): `OnboardingSecurityTest` needs the ClamAV service, and one `SyntheticRecoveryTest` SMTP test belongs to the dedicated recovery environment.

New platform checks in `tests/Integration/rls.sql`, against the real runtime role: no platform access or directory read without a grant; no self-grant or direct audit write; a grant is unusable without a confirmed authenticator, without step-up or with a stale version; capabilities outside the grant are refused; the grant row is readable only by its holder and never writable; workspace and account suspension deny membership, tenant rows and (for the account) administration, and the runtime cannot read or lift a suspension; reactivation restores access but not a revoked membership; all nine platform tables have forced RLS, no runtime owner and no policy except the grant self-read; every administration function the runtime can execute is a definer function owned by the migration role with a pinned search path and no PUBLIC execute; internal cross-tenant helpers are not executable; no cleanup function deletes records.

New acceptance tests in `PlatformGovernanceTest`: the paused flag in both directions, its exact projection, nothing disclosed to a non-participant, and an unchanged partnership; work due during an account suspension recorded as skipped with its audit event, moved to the next occurrence, not queued, pending email cancelled, an already-ready run skipped, nothing replayed after reactivation, and the skip function refusing work that is not suspended; date filters (inclusive end, invalid ranges rejected) and the two Overview figures, including null outside the capabilities and a stale dry run not counted.

## Rendering and accessibility findings

Inspected with temporary captures (removed) and automated checks at 1440 and 768 wide on desktop and 390 and 320 on mobile.

- Brand roster and partner portal with a paused partnership: no axe violations and no page-level overflow. The badge pairs colour with text and an icon, and the sentence below it states the consequence without the word "suspended".
- Accounts with the date range applied: no axe violations or overflow at 390 and 320 or at the desktop widths, checked inside the administration journey. The date inputs have visible labels.
- The Overview and Workspaces changes add no new layout; they were scanned by the journey at every reference width.

## Unverified gates

- WebKit was not run locally: the host lacks WebKit system libraries. It ran in CI on `main` before this change, and will run in CI on this change's pull request.
- The original mobile failures were not reproduced, so their cause is not established (see B1).
- Native Safari, screen-reader and browser-zoom review: not done (handoff item B15).
- The scheduler-driven skip was exercised by calling the dispatch services in acceptance tests, not by a running scheduler container.
- Staging and production behaviour: not exercised. Nothing was deployed.
- The Playwright JSON reporter still cannot write `src/test-results/results.json` (root-owned folder); the list reporter output is the record.
