# Release candidate evidence: `9a65301`

**Scope (2026-11-23):** CI evidence for M1-05 and the CI part of M6-08, for one exact revision. This records what CI verified; it does not approve a release (M6-09), and it does not close M6-08, whose load, outage and restore drills and native Safari checks remain open.

| Item | Value |
| --- | --- |
| Candidate | `main` at `9a65301b8a9b0db9be4741e487641044172e8330` (merge of cyrusmanatad/prm#90, 2026-11-23 01:48:56 UTC) |
| Contents since the last staging deployment (`c7e1f77`) | cyrusmanatad/prm#89 (CI flake fixes: test waits and Composer download retry) and cyrusmanatad/prm#90 (PRIV-05 report-only upload reconciliation: migration `2026_11_28_000001_add_upload_reconciliation`, a daily scheduled command and an admin card) |
| Staging | **Deployed 2026-11-23 02:17 UTC** (from `c7e1f77`; one migration, daily 03:15 UTC job). The first reconciliation run against R2 found no findings. See the [staging log](../shared/staging-deployment.md). |

## CI runs for the candidate

**All passed** (push to `main`, attempt 1, no reruns or flaky retries).

| Workflow, run | Job (job ID) | Result | Time (UTC) |
| --- | --- | --- | --- |
| Foundation frontend, [run 45096678265](https://github.com/cyrusmanatad/prm/actions/runs/45096678265) | `components` (137437902855) | passed | 01:49–01:50 |
| Backend foundation, [run 45096678228](https://github.com/cyrusmanatad/prm/actions/runs/45096678228) | `backend` (137437903018) | passed | 01:49–01:55 |
| | `clean-setup (desktop)` (137437903023) | passed: browser 63 passed, 7 skipped | 01:49–02:04 |
| | `clean-setup (mobile)` (137437903075) | passed: browser 64 passed, 6 skipped | 01:49–02:04 |
| | `clean-setup (webkit-desktop)` (137437902988) | passed: browser 63 passed, 7 skipped | 01:49–02:06 |
| | `clean-setup (webkit-mobile)` (137437902852) | passed: browser 64 passed, 6 skipped | 01:49–02:06 |

Every clean-setup job also ran these suites on a fresh database, all passing: acceptance (255 tests, 6,131 assertions, 3 skipped), PostgreSQL integration (44 tests, 873 assertions), identity, and frontend types, tests and build. The evidence artifacts (`foundation-clean-setup-evidence-{desktop,mobile,webkit-desktop,webkit-mobile}`) stay on the run.

The skipped browser tests were not listed one by one here. Their counts match the suite's environment-gated tests: `privacy-closure` needs a disposable stack (`CLOSURE_TEST_COMPOSE`), `owner-mfa` needs owner MFA enforced (off in CI), and some tests apply to one viewport only. A skip is not a pass for that test.

## What these workflows verify

At this revision both workflows run on every push and pull request (`.github/workflows/`); cyrusmanatad/prm#91 limits push runs to `main` so pull-request commits run once. Actions are pinned by commit SHA.

| Workflow and job | Checks |
| --- | --- |
| Foundation frontend: `components` | Locked `npm ci`; Prettier on changed files; dependency advisories (`check-npm-audit.mjs`, with a self-test); `vue-tsc` types; Vitest component tests; production build. |
| Backend foundation: `backend` | Secret scan (`check-secrets.py`, with its exception tests); `composer validate --strict`, `composer audit --locked` and Pint; identity tests; migrations then restricted runtime grants; PostgreSQL role and row-policy checks (`rls-fixtures.sql`, `rls.sql`); PostgreSQL isolation and real Redis worker tests (`phpunit.pgsql.xml`); membership concurrency and synthetic recovery (`check-recovery.py`); container smoke test (`/up`). |
| Backend foundation: `clean-setup` (`desktop`, `mobile`, `webkit-desktop`, `webkit-mobile`) | From a fresh checkout (`check-clean-setup.py`): image build with `--pull`; dependencies; migrate and grants; a portal migration rollback and reapply with seed and check data; identity, PostgreSQL integration and acceptance suites; frontend install, types, tests and build; then the whole Playwright browser suite in that browser project (Chromium desktop and mobile, Linux WebKit desktop and mobile). The evidence for each job is kept as an artifact. |

## Recent `main` history

| Revision | Frontend | Backend | Notes |
| --- | --- | --- | --- |
| `1ac9be7` (#87) | passed | [failed](https://github.com/cyrusmanatad/prm/actions/runs/45041368174) | WebKit mobile outreach flake, fixed in #89 |
| `c7e1f77` (#88) | [passed](https://github.com/cyrusmanatad/prm/actions/runs/45043366796) | [passed](https://github.com/cyrusmanatad/prm/actions/runs/45043366792) | Deployed to staging |
| `8bf1c9e` (#89) | [passed](https://github.com/cyrusmanatad/prm/actions/runs/45046644408) | [passed](https://github.com/cyrusmanatad/prm/actions/runs/45046644438) | CI flake fixes ([evidence](../shared/ci-flakes-evidence.md)) |
| `9a65301` (#90) | [passed](https://github.com/cyrusmanatad/prm/actions/runs/45096678265) | [passed](https://github.com/cyrusmanatad/prm/actions/runs/45096678228) | This candidate |

## Local verification of the newest change

PRIV-05 was verified locally before merging ([evidence](../m3/orphan-report-evidence.md)): 15 PostgreSQL acceptance tests, a migration rollback and reapply with grants, 232 component tests, and the administration browser journey on desktop and mobile.

## What this does not cover

- **M6-08 drills:** load and capacity (M6-02), outage, rollback and restore drills (M6-01, M6-04).
- **Native browsers:** macOS and iOS Safari, which Linux WebKit does not replace (M1-03).
- **Manual reviews:** screen reader, keyboard and zoom (M1-01, M1-02).
- **Production:** provider, storage, privacy and pricing decisions (M3-01, M3-02, M4-01, M6-06, M6-09).
