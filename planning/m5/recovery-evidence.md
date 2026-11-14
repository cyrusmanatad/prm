CI repair and final PR verification: [repair evidence](ci-repair-evidence.md).

# M5-10 synthetic recovery evidence — 2026-11-12

Status: **implemented and locally verified**. Working tree based on `41e0cd46da3c02047d2c8abcfaa631cb8dfd9db7` (`main`). [Recovery contract](recovery-contract.md). No application behavior, schema, endpoints or UI changed. M5/M6 remain incomplete.

## Environment and commands

Docker ran in WSL Ubuntu using standalone [recovery Compose](../../compose.recovery.yaml), project `weavdash-m5-recovery-complete1112`, PHP 8.5.11, PostgreSQL 16 and Redis 7.4 AOF (`appendfsync always`). Every network is internal; no ports are published. Dedicated volumes hold PostgreSQL, Redis, private storage and SMTP capture. No shared/staging database, service, queue, storage or environment configuration was used for faults.

Prepare a separate `/tmp/weavdash-m5-recovery-verified` copy of `src/`, excluding `.env`, `node_modules`, `vendor`, `storage` and `bootstrap/cache`. Install dependencies from the current lock file with `composer install --no-interaction --prefer-dist --no-scripts --no-progress`; copying an older vendor cache alone is insufficient. Supply built Vite assets, an empty `.env` and writable Laravel storage/framework/{cache,sessions,views}, storage/logs and bootstrap/cache. Restore write permissions on copied files as well as directories. The cached `weavdash-app:latest` image supplied PHP/extensions and build assets; `RECOVERY_IMAGE` can select another suitable locally built image. Source must be an immediate temporary-directory child named `weavdash-m5-recovery-*`, with no cached configuration.

The pinned ClamAV image uses an offline synthetic EICAR signature database for the existing real-scanner test; production signature freshness is outside this evidence. Application workers remain unprivileged. The test-only SMTP sink runs as root to write its separate capture volume and rejects a message if capture persistence fails.

From the WSL checkout:

```sh
PYTHONDONTWRITEBYTECODE=1 python3 src/scripts/test-recovery-guard.py
RECOVERY_SOURCE=/tmp/weavdash-m5-recovery-verified \
  PYTHONDONTWRITEBYTECODE=1 python3 src/scripts/check-recovery.py \
  --project weavdash-m5-recovery-complete1112
```

Use a fresh project suffix per run. The runner creates the broker directory, migrates its own volume, applies runtime grants, crashes worker/scheduler Artisan processes, stops background consumers for fixture coordination, runs `phpunit.acceptance.xml`, `phpunit.pgsql.xml`, `phpunit.xml` and Pint, then removes its services/data volumes. `--filter SyntheticRecoveryTest` supports focused acceptance diagnosis. The retained `check-process-restarts.py` entry point uses the same inspector and requires a running dedicated stack with `RECOVERY_SOURCE`/`RECOVERY_CONTROL` set; the full runner handles these prerequisites.

## Results

| Gate | Observed result |
| --- | --- |
| Full PostgreSQL/Redis acceptance | **144 tests / 3,403 assertions passed**, **401.644 s**. All eight new synthetic recovery tests ran with the broker; none were skipped. |
| SMTP capture and replay | **6 messages / 6 unique Message-IDs** persisted in the service sink; terminal replays added none. Existing loopback SMTP/worker-death probes also passed. |
| PostgreSQL integration | **35 tests / 635 assertions passed**, **6.164 s**, against the same isolated schema/runtime role. |
| PHP unit/feature | **42 tests / 362 assertions passed**, **4.221 s**, after installing missing locked AWS dependencies in the temporary copy. No external AWS requests occurred. |
| PHP formatting | Pint `--test tests/Acceptance tests/Fixtures`: **43 files passed**, including every changed PHP file. |
| Target refusal | **2 test methods passed**: valid synthetic identity plus rejected shared names, project/service/marker mismatches, published ports, external/shared networks, foreign volumes and unexpected binds. |
| Documentation | **445 local file links** and `git diff --check` passed across the changed documentation. |
| Source scanner | Synthetic positive control passed; **no new findings**. The same two pre-existing evidence SHA-256 entries remain flagged by `generic-api-key`: `planning/evidence/m5/schedules/source-sha256.json:7` and `planning/evidence/pipeline-drag/source-sha256.json:10`. Rules/suppressions are unchanged. |
| Cleanup | Docker project-filtered container, volume and network lists were empty after the run. SMTP captures disappeared with their volume; no new screenshots were retained. |

The broad runner passed acceptance/integration, then stopped because the copied vendor cache lacked `Aws\MockHandler`. Installing from the current lock added five packages, with zero updates/removals. The focused PHP suite and final Pint/guard checks then passed using `docker run --rm --network none` against the same temporary source. Unchanged acceptance/integration dependencies were not retested. Earlier harness setup checks also corrected missing build/scanner prerequisites, an acknowledgement-file visibility race (now atomic rename), copied-file permissions and capture-volume ownership. No production policy or assertions were weakened.

The successful PHP follow-up used:

```sh
docker run --rm --network none -e APP_ENV=testing \
  -e APP_KEY="$(php -r 'echo "base64:".base64_encode(random_bytes(32));')" \
  -v /tmp/weavdash-m5-recovery-verified:/var/www/html \
  -w /var/www/html weavdash-app:latest vendor/bin/phpunit -c phpunit.xml
```

The same image/source mount ran `vendor/bin/pint --test tests/Acceptance tests/Fixtures`. Source scanning used `PYTHONDONTWRITEBYTECODE=1 python3 src/scripts/check-secrets.py` from the repository root.

## CI caller follow-up

The backend workflow previously called the guarded restart script with the ordinary `weavdash` project. The [workflow](../../.github/workflows/backend.yml) now exports source, locked dependencies and built assets from its freshly built application image into a separate temporary directory, grants container read/write permissions, and runs the full recovery runner under a distinct `weavdash-m5-recovery-ci<run-id><attempt>` project. An EXIT trap removes the export container and temporary source after success or failure. The normal smoke/browser stack remains separate; backend job timeout is 30 minutes to accommodate the expanded acceptance suite.

Validation: a separate fresh local image build (`docker build -f src/docker/Dockerfile -t weavdash-m5-recovery-ci-check:local src`) and actionlint 1.7.7 (including ShellCheck) passed. Executed the workflow's exact `run` block under Bash `-e -o pipefail`, substituting that verification image and setting `GITHUB_RUN_ID=local1112`, `GITHUB_RUN_ATTEMPT=1`. The recovery runner exited **0** in **476.4 seconds**: acceptance **144/3,406** (**412.756 s**), integration **35/635** (**6.372 s**), PHP **42/362** (**4.295 s**), Pint **43 files**, target guards **2 tests**, and **6 captured messages / 6 unique Message-IDs**. This fresh-image run completed all gates in one invocation, including the previously missing AWS dependency.

The source/export cleanup trap completed successfully; no temporary recovery source directory remained. The initial source-permission failure also propagated a nonzero exit and cleaned up its temporary source, verifying failure cleanup. Remote GitHub execution and downstream browser journeys remain unverified by this follow-up; the existing smoke/browser steps are unchanged.

## Recovery observations and boundaries

Actual Artisan SIGKILL triggered worker/scheduler container restart. Real Redis AOF restart retained queued work; isolated queue loss reconstructed schedules, ready runs and notification intent from PostgreSQL. Fresh workers replayed reminder/digest/preparation stages once; onboarding replacement, submission, correction and acceptance mail recovered through real workers and local SMTP. Approved outreach/review notices retained exact-version, account-only consent and current authorization checks.

A real Redis outage preserved encrypted pending intent with sanitized `queue_unavailable` feedback and durable backoff. PostgreSQL workspace/global budget records survived Redis loss; fair dispatch survived restart. The dispatch lease test waited the actual **60 seconds**, and existing scheduler acceptance waited its real mutex TTL. Worker death during SMTP DATA, disconnection after DATA and SMTP outage preserved terminal uncertainty; reconciliation, Redis reclaim and audited operator resolution could not requeue it. Another eligible workspace progressed after SMTP restoration.

Logged replies, invitation replacement/revocation, consent withdrawal, membership/identity restoration and approval invalidation retained cancellation across restart. Existing real PostgreSQL RLS, transaction rollback and competing lock-order tests passed. Spring/fall downtime coalesced one occurrence; deterministic clock tests retained DST gap/fold and fractional-offset coverage.

Frontend/browser/rendering gates are **not applicable: no UI changed**. M5-09 recipient-controlled provider delivery/receipt/link opening, production rate/daily/spend budget approval, remaining administration, broader UI/manual/accessibility/Safari/zoom checks, current-candidate remote CI/clean setup and inherited M5/M6 production/commercial gates remain open. Local SMTP acceptance establishes no external receipt; M5-10 alone completes neither M5 nor M6.
