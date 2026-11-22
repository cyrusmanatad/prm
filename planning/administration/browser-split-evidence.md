# Administration browser tests split — verification

Scope: test-only change to `src/tests/Browser/administration.spec.ts` and `admin-fixture.php`. No application, UI or data-contract change. Date: 2026-11-22. Revision: branch `claude/dazzling-raman-fb5c4b`, rebased on `main` at `908fc79`. It includes the PR #78 directory-filter expectations and the PR #79 plain-language audit labels and Outcome navigation check.

## Change

- The single journey ("platform owner reviews directory, entitlements, operations, audit and operator access", 8–10 minutes per project, timeouts raised from 8 to 10 minutes) is now 16 independent tests. Each one seeds its own synthetic workspace owner and platform owner through `admin-fixture.php`: access and outsider denial, six module sweeps of one or two modules, directory filters, entitlements, diagnostics, operator access, account suspension, support access, cleanup, workspace suspension and the mobile drawer (skipped on desktop, as before). The file uses `test.describe.configure({ mode: 'parallel' })`. The global configuration still uses one worker, so `--workers=N` is opt-in. No test sets a custom timeout; all use the 60-second default.
- Every assertion, `clean()` axe/overflow scan and `confirmMutation` lifecycle check is kept. The final audit section moved into the tests that create each event: Request denied and the Denied outcome link (access), Platform access revoked with its reason (operators), Support access ended, Cleanup dry run, and Workspace suspended/reactivated. Each filters the audit list by the test administrator's email (`?q=`, which matches the actor email) so tests running alongside cannot push its events off the first page.
- TOTP: verification happens once per test and the session is reused. Each `prove()` still sends the password and a new single-use code. The `authenticator()` helper uses any unused step that the server's ±1-step window still accepts for the required margin (8 s for the challenge, 20 s for a proof). As a result, a challenge plus two proofs normally need no waiting, and at most one wait of up to 30 s occurs per test. Before, every proof waited for a new step.
- Teardown: every test now creates a platform owner, and `/admin/operators` lists every grant on one page without pagination. Without cleanup the page grew by about 25 rows per run, and its axe scans reached 45 s after five runs. `admin-fixture.php <email> release` (same local/array-mail/migration-role guards) deletes only that test's grant and its peer's grant. Accounts, workspaces and audit events are kept. If the image predates this mode, the test records a warning annotation and does not fail.
- Captures remain opt-in through `CAPTURE_BROWSER_IMAGES=1` and are written to the test output directory. No images were kept.

## Commands and results

From `src/` unless stated otherwise.

| Command | Result |
|---|---|
| `npm run format:changed`, `npm run format:check:changed` | Formatted; check passed |
| `npm run typecheck` | Passed |
| `vendor/bin/pint --test tests/Browser/admin-fixture.php` (in the `migrate` image) | Passed |
| `COMPOSE_PROJECT_NAME=weavdash npm run test:browser -- tests/Browser/administration.spec.ts --project=desktop --project=mobile` against the running `weavdash` stack | Not valid evidence for `main`. That stack was built from another worktree with uncommitted admin UI changes. At that time its audit labels ("Request denied") were ahead of `main` (`request.denied`), so the four audit checks failed on wording only, after the flows completed. PR #79 later brought those labels to `main` and the tests now expect them. It also has 926 active platform grants, so axe on `/admin/operators` timed out at 60 s. Stopped after 15 tests. |
| Final, on `908fc79`: same command with `COMPOSE_PROJECT_NAME=weavdash-split PLAYWRIGHT_BASE_URL=http://localhost:8030`, rebuilt disposable stack, one worker | **31 passed, 1 skipped, 6.8 min**. Slowest test 22.7 s. Grants: 0 after the run. |
| Final, on `908fc79`: same, `--workers=2` | **31 passed, 1 skipped, 4.4 min**. Slowest test 32.1 s. Grants: 0 after the run. |
| Earlier, on `54da336`: same command with `COMPOSE_PROJECT_NAME=weavdash-split PLAYWRIGHT_BASE_URL=http://localhost:8030` against a disposable stack built from this tree (scratch override: port 8030, no PostgreSQL host port, no ClamAV, `PHP_CLI_SERVER_WORKERS=4`, array mail), one worker | **31 passed, 1 skipped, 8.2 min** for both projects. Slowest test 38.8 s (mobile Overview/Accounts sweep); most take 7–27 s. Platform grants: 0 after the run. |
| Earlier, on `54da336`: same, `--workers=2` | **31 passed, 1 skipped, 4.3 min**. Slowest test 31.7 s. Grants: 0 after the run. |
| Same, `--workers=4` (earlier revision) | 2–6 timeouts at unrelated steps (`page.goto`, click, axe) with 8 cores and 9 GB shared with another stack. Not recommended on this host. |

## Unresolved gates

- WebKit projects were not run locally (CI only, as before).
- The shared `weavdash` stack keeps its 926 synthetic grants from earlier runs, so `/admin/operators` scans there stay slow until that data is reset. The test's teardown cannot remove grants created before this change, and none were removed here.
- `/admin/operators` has no pagination. This only matters with many synthetic grants, but it is the underlying reason axe scans on that page slow down.
