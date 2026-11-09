# Staging R2 verification

Scope/date: 2026-11-09, `3887f7d` plus preserved staging working tree and this R2 integration. **Staging is deployed on private R2** using `weavdash-staging-app:20261109-r2` and matching web image. Local development remains local. No production rollout or external email occurred.

## Implemented

- Locked `league/flysystem-aws-s3-v3` 3.35.3 with AWS SDK 3.403.1 and three transitive packages. All previously locked production versions are unchanged.
- Opt-in private `onboarding` S3 disk, default local; explicit local/R2 maintenance disks. Existing object keys, downloads, scanner checks and upload limits are unchanged.
- Optional private raw `storage.env` shared by app, worker, scheduler and migrate; credentials excluded from web/database/Redis configuration and root private files ignored by Git.
- Staging/migration-role inventory and copy/verify commands; SHA-256/size verification before and after copying; conflicting destination refusal, resumability and reverse-copy recovery. No automatic deletion, driver switch or metadata modification.
- Staging synthetic R2 write/read/size/delete probe with cleanup and redacted failure output. See [configuration and recovery](staging-r2-storage.md).
- Reused `StagingBanner` and the existing onboarding form warning; copy now says private staging storage instead of local/server storage. No new components, styling system, schema, upload limits or authorization changes.

## Commands and results

| Gate | Result |
| --- | --- |
| Adapter installation | Passed through disposable PHP 8.5 container using a reachable official Packagist CDN address. Host/container default DNS route timed out initially; no permanent DNS/repository change was made. |
| `vendor/bin/phpunit --filter 'OnboardingStorageTest\|StagingFeaturesTest'` | Passed: 12 tests, 97 assertions. Real installed S3 adapter with mocked AWS transport; transfer/resume/reverse, conflict/corruption/missing-target rejection, normal-deployment refusal and probe cleanup. Fake storage does not certify R2. |
| `vendor/bin/phpunit -c phpunit.pgsql.xml --filter OnboardingTest` | Passed against real PostgreSQL: 10 tests, 193 assertions. Includes tenant/foreign-file denial, scan states, authorized download/removal, staging opt-in and failed-write metadata/version rollback. |
| Local migration-role `onboarding:storage inventory` | Passed: 26 synthetic referenced documents, 957 bytes across tenants. Current source mounted into the local migration container; its baked image predates these commands. No files copied or database records modified. |
| Operational guard refusals | Passed: staging runtime role denied all-tenant inventory; migration-role copy without `--writers-paused` refused before touching storage. Both exited with failure as required. |
| Synthetic staging Compose configuration | Passed with `--profile tools config --format=json` captured privately: R2 settings reach all four backend consumers only; absent optional `storage.env` preserves local defaults. Initial check omitted the tools profile and was corrected. |
| `composer validate --strict` | Passed. |
| Focused Pint `--test` | Passed for all five new/configured PHP files; existing onboarding integration test retained its pre-existing formatting to avoid unrelated churn. |
| `npm run format:check:changed`, `npm run typecheck`, `npm run build` | Passed: eight changed supported frontend files checked, Vue types and production build. This integration introduces no UI markup changes. |
| `COMPOSE_PROJECT_NAME=weavdash-professional npx playwright test tests/Browser/onboarding.spec.ts --grep 'creator invites' --project=desktop --project=mobile --output=/tmp/weavdash-r2-browser --reporter=list` | Passed: two attachment journeys, including upload/download, confirmed removal, submission/correction and access revocation. Axe and horizontal-overflow checks passed. First run passed both journeys but failed writing the inherited JSON reporter file; console-reporter rerun exited successfully. |
| `git diff --check` | Passed. |

Visual inspection: existing form/cards/controls and attachment actions at 1280×720 desktop and 390×664 mobile. No horizontal overflow; mobile actions wrap. Rapid test actions leave stacked existing toast notices over the top of the form in captures. Normal local scanning labels are shown; staging unscanned/R2 rendering is not claimed. All temporary PNG captures were inspected and removed. No repository screenshots were added.

## Unresolved gates

- Cloudflare dashboard settings (public development URL, custom domains, location and lifecycle) were not independently inspected; retain the private bucket settings in the [runbook](staging-r2-storage.md). The S3 credential successfully performs bucket listing and object write/read/delete; no account administration permission was requested.
- `composer audit --locked --format=json` reports seven advisories across existing `symfony/http-foundation` 7.4.20, `symfony/http-kernel` 7.4.20, `symfony/mime` 7.4.19 and `symfony/routing` 7.4.20. Versions match `HEAD` exactly; none of the five new packages was flagged. The audit gate is not passing; remediation is a separate dependency update and must precede a claim of clean dependency security.
- `python3 src/scripts/check-secrets.py` passes its positive control but reports the two previously documented evidence-hash findings at `planning/evidence/m5/schedules/source-sha256.json:7` and `planning/evidence/pipeline-drag/source-sha256.json:10`. Rules unchanged; no new finding.
- Inherited M5/M6, malware scanning and production release gates remain open.

## Live migration and verification

**Confirmed — 2026-11-09:** user supplied credentials through a local file. Its mistaken repository location was detected, moved into a mode-700 directory under `/tmp`, and merged into mode-600 configuration without printing values. No credential file remains in the repository. On the server, `storage.env`, `staging.env` and `smtp.env` are mode 600 and the staging directory remains private.

Both images were built locally from the pinned Dockerfile and transferred over SSH. Bundle SHA-256 matched at the destination: `e1d19073dca0a703fa559e9c67562fc67cadc2bcdebf064ba031eb931f51be03`. The bucket was empty before copying. Synthetic R2 write/read/size/delete passed from local PHP, the release image and the server's candidate migration tool.

After cutover, runtime configuration checks passed separately in app, worker, scheduler and migrate: `s3`, bucket `weavdash`, private visibility, exceptions and path-style requests enabled, both credentials populated without displaying them. All six services are running; PostgreSQL, Redis and web report healthy. Temporary deployment/validation files and the image bundle were archived under the server backup's `release/` directory.

The staging maintenance sequence ran **13:27:14–13:27:57 UTC**: stopped web/app/worker/scheduler, backed up PostgreSQL/private attachments/configuration, inventoried **1 document / 48 bytes**, copied it under its unchanged key, independently verified size/SHA-256, selected `s3`, and recreated all backend consumers plus web. Before/after database counts matched. Original local bytes, old images and the server backup at `/home/cyrusadmin/weavdash-staging/backups/r2-20261109` are retained. A protected copy was transferred off-server to `/tmp/weavdash-r2-private/server-backup`; its PostgreSQL custom header, readable attachment archive and matching counts were checked. The temporary off-server copy is not a durable backup service.

Final source passed formatting, types, production build, **75 component tests**, the **12/97** focused backend suite and **10/193** PostgreSQL onboarding suite. The earlier audit/source-scan limitations above remain explicit.

Live `scripts/verify-staging.mjs` passed all three reviewer experiences at **390×900** and **1440×900**: login/logout, secure sessions, navigation, no platform access, all registration variants/validation and password recovery, no horizontal overflow or browser errors. The attachment journey exercised a **2 MiB text upload**, modal Cancel focus/cancellation, confirmed removal, then a new unscanned R2 upload, exact-byte download and submission. A separate migration-role validator confirmed the removed object is absent from R2, checksum/size match, pending/rejected downloads fail, and unscanned downloads require both staging opt-ins. Its temporary scan-state updates were rolled back.

Authorized guest and owner downloads returned exact bytes; anonymous access returned **403** and a foreign Professional workspace returned **404**. The owner review retained the unscanned label. The same persisted reference was checked again after replacing the app/web containers. Final inventory is **2 documents / 96 bytes**: migrated original plus the synthetic submitted reference. No source files were deleted, no database migration was needed and no real email was sent.

Visual inspection of the **390×900** guest form and **1440×900** owner review confirmed existing cards, typography, controls, wrapping and clear unscanned warnings. New storage wording is accurate. Rapid test actions retain existing stacked toast behavior; no new layout issue was observed. All new PNG captures were temporary and removed after inspection. Resource checks after the journeys showed about **699 MiB available RAM**, zero OOM events and zero container restarts.

## Dedicated commit verification — 2026-11-09

Rechecked the final staging/R2 working tree based on `3887f7d`: changed-file Prettier, TypeScript, 75 component tests, production build, Composer strict validation, focused Pint (11 PHP files), staging/storage PHPUnit (12 tests / 97 assertions), PostgreSQL onboarding (10 / 193), and desktop/mobile onboarding browser journeys (2 passed). PHP suites ran in the existing isolated PHP 8.5 container because host PHP 8.3 is below the installed dependencies’ requirement. Chromium initially failed under the sandbox; the authorized rerun passed.

Inspected the browser form captures at 1280×720 and 390×664: existing cards/controls retained, mobile attachment actions wrap, no horizontal overflow; existing stacked toasts cover the upper form during rapid test actions. Temporary PNG files were removed after inspection. Current staging summaries now reference the subsequent verified rollout. This commit session performed no deployment or external email. Secret scanning again found only the two unchanged evidence-hash false positives listed above; dependency advisories and inherited release gates remain open.
