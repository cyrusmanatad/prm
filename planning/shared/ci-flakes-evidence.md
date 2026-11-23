# Intermittent CI failures on `main`: causes and fixes

**Scope (2026-11-23):** the Backend foundation workflow failed on most `main` merges of 2026-11-22 (`78861c1`, `8c9931a`, `05de146`, `a5782cc`, `7cd13d0`, `1ac9be7`) in different jobs, while PR runs of the same code usually passed. Branch `test/ci-flakes` from `origin/main` at `c7e1f77`. Test and build changes only; no application change.

## Failures found

Each failed run's job list was read, and for the clean-setup browser jobs, the uploaded evidence (`browser.log`, failure snapshots).

| Failure | Runs | Cause | Fix |
| --- | --- | --- | --- |
| Acceptance `PlatformConsoleTest::test_list_counts…` (backend job, or Chromium mobile and WebKit mobile clean-setup) | `8c9931a`, `05de146`, and PR runs before #85 | The audit search used a new account's numeric ID, which also matched browser-test emails containing the same digits. | Already fixed in cyrusmanatad/prm#85 (search by a unique synthetic actor); no failure since. |
| `outreach-templates.spec.ts:54`, WebKit mobile: "Latest pitch" never clickable | `1ac9be7` | `showOutreachPanel` used `isVisible()`, which does not wait. After `page.reload()` WebKit can still be mounting, so the Templates toggle was skipped and the list stayed hidden on narrow screens. PR #76 fixed this for the second tab only. | The helper waits for the editor (`Template name`) before checking the toggle. The same helper in `professional-ui.spec.ts`, and its Media kit twin `showKitSection` (which waits for the page heading), get the same wait. |
| `automation.spec.ts:87`, WebKit desktop: Weekly digest still checked after reload | `7cd13d0` | The test reloaded straight after confirming "Withdraw notice consent". WebKit can cancel the in-flight save on reload. | Wait for the confirmation to close and "No unsaved changes" before reloading. |
| `crm.spec.ts:287`, WebKit mobile: axe `color-contrast` | `a5782cc`, and the PR run `ecfa98e` | Both failure snapshots show two success toasts ("Reminder snoozed…", "2 contacts deleted") on screen during the scan. Axe measured contrast while they were still fading in. | `settleNotificationAnimations` before the scan, as `finance.spec.ts` already does. |
| Build: `composer install` exit 100 (three clean-setup jobs) | `78861c1` | GitHub's package download API returned HTTP 504; Composer does not retry. | `src/docker/Dockerfile` and `staging.Dockerfile` retry `composer install` up to three times (10 s, then 20 s, apart) before failing. |

## Verification

| Command | Result |
| --- | --- |
| Browser `outreach-templates`, `professional-ui`, `automation`, `crm`, Chromium desktop and mobile, `--repeat-each=2` | 36 passed. |
| `docker compose build --no-cache app` and the staging `app` target | Both built through the new retry loop. |
| Prettier and type check on the changed specs | Passed. |

## Unverified gates

- WebKit locally: the host lacks WebKit's system libraries. The PR's clean-setup jobs run WebKit desktop and mobile, and one green run does not prove an intermittent failure gone; repeated runs on `main` will show it.
- The Composer retry path itself (a real 5xx) was not reproduced; the loop was exercised only on a successful first attempt.

## Duplicate runs (2026-11-23)

Both workflows ran on every `push` and every `pull_request`, so each commit on a pull-request branch ran twice (for example `7b36dce`: Backend foundation #307 for the push and #308 for the pull request), and each pair of backend runs held ten roughly 20-minute jobs at once. That queued later `main` runs behind them.

Both workflows now:

- run on `push` only for `main`, so every merge still gets a full run for the release evidence;
- run once per pull-request commit (`pull_request`, which tests the merge with `main`);
- can be started by hand on any branch (`workflow_dispatch`);
- cancel a pull request's superseded run when a newer commit arrives (`concurrency`, cancelling only for `pull_request`; `main` runs are never cancelled).

The formatting step already takes its base from the pull request's base or the previous `main` commit, and a manual run falls back to the merge base with `main`. Verified by parsing both files; the first pull request after this change shows one run per workflow.
