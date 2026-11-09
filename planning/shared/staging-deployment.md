# Staging deployment

**R2 deployed (2026-11-09):** private staging attachments now use bucket `weavdash`, with app/web images tagged `20261109-r2`. See [private R2 configuration, migration and recovery](staging-r2-storage.md) and [live verification](staging-r2-evidence.md). The local-storage deployment evidence below is historical; the original private volume is retained for recovery. Staging upload limits and unscanned labels remain unchanged.

**Local review fixes (2026-11-09):** both P2 findings are resolved in the working tree: preserve the client address sanitized by the trusted edge, and allow authorized removal of unsubmitted attachments while new uploads are disabled. See the [review resolutions and verification](staging-review.md): feature tests 6/74, PostgreSQL onboarding tests 9/185, four desktop/mobile browser journeys, actual isolated Nginx/FPM proxy checks, formatting/types/build and focused Pint passed. Existing work is preserved. These fixes were subsequently included in the verified R2 staging rollout; see the [current live evidence](staging-r2-evidence.md). The older deployed-image evidence below retains its original scope. M5/M6 and inherited release gates remain open.

## Current scope

**Confirmed — 2026-11-08:** staging is the existing colleague-test environment at **https://weavdash.cyrusmanatad.com**, on `cyrusadmin@216.219.85.134`. The user enabled normal Partner, Brand and Professional registration, email, and local file uploads. The interface, configuration, scripts and deployment documentation now use **Staging**. Production OVH Object Storage/S3 selection remains future work; no storage subscription or live customer-data import is included. M5/M6 and inherited release gates remain open.

**Confirmed:** because the shared VPS cannot accommodate ClamAV, the user explicitly chose **clearly labelled, unscanned test uploads in staging only**. This supersedes the original no-upload boundary. Local storage and antivirus scanning are separate choices.

**Historical 2026-11-08 baseline:** source `3887f7d` plus that working tree. Images: `weavdash-staging-app:20261108-features` and `weavdash-staging-web:20261108-features`. Transferred bundle SHA-256: `b85beefd543865832945a4a0b82745df267e9a675c4c91bf3d73ea458ecba990`.

Canonical files: [compose.staging.yaml](../../compose.staging.yaml), [Dockerfile](../../src/docker/staging.Dockerfile), [edge snippet](../../src/docker/staging-edge.conf.example), [configuration generator](../../src/scripts/create-staging-config.py), and [browser verifier](../../src/scripts/verify-staging.mjs). VPS directory: `/home/cyrusadmin/weavdash-staging`; private runtime files are `staging.env`, `smtp.env`, `storage.env` and `reviewers.json`, mode 600 inside a mode-700 directory. Never put these files or session/token handoffs in Git.

## Registration and accounts

Public registration and normal email verification are available at `/partner/register`, `/brand/register`, `/professional/register` and the generic `/register`. Password validation, verification, throttling, CSRF protection and workspace provisioning use the existing application flows. Normal role and subscription rules remain intact; registration does not grant platform administration or bypass Professional subscription activation. Prepared reviewer workspaces retain their demonstration subscriptions.

Existing reviewer login identifiers and passwords are preserved:

| Experience | Email | Login |
| --- | --- | --- |
| Partner | `partner@preview.example.test` | `/partner/login` |
| Brand | `brand@preview.example.test` | `/brand/login` |
| Professional | `professional@preview.example.test` | `/professional/login` |

The older email domain is an issued identity, not environment branding. These synthetic addresses cannot receive mail; colleagues should register using real inboxes or explicitly select a real recipient for invitation email. Default reviewer workspace labels are now Staging; customized names, immutable plan history, audit history and account credentials are preserved.

Passwords are in `/home/cyrusadmin/weavdash-staging/reviewers.json` on the VPS. `staging:bootstrap /run/reviewers.json` requires staging mode and migration credentials, creates only missing synthetic reviewers with 90-day demonstration subscriptions, and preserves existing records. It grants no platform permissions.

## Attachment storage and production boundary

`UPLOADS_ENABLED=true`, `STAGING_MODE=true` and `STAGING_UNSCANNED_UPLOADS=true` enable the approved test-upload behavior. **Current storage:** R2 via private `storage.env` shared by app/worker/scheduler/migrate; include referenced R2 bytes in future backups. **Historical local baseline and retained recovery source:** uploaded bytes lived in the **local Docker volume `weavdash-staging_private-storage`**, mounted at `/var/www/html/storage/app/private` for application, worker, scheduler and migration tools. The volume survives container/image replacement. It is private, has no public storage URL, and is included in backups separately from PostgreSQL. Runtime uploads are excluded from Docker build contexts. Build instructions create the empty private directory before assigning it to the non-root application user; a fresh anonymous-volume write check verifies its permissions.

The existing PDF/PNG/JPEG/plain-text MIME checks, 2 MiB per-file limit, five-file-per-request limit, record versions, sharing consent, capability/workspace authorization, checksums and protected attachment downloads remain enforced. Optional files can be uploaded, downloaded and submitted in the test journey.

Staging files use a distinct `unscanned` database state and the visible label **Unscanned (staging only)**. The banner and form explain that malware scanning is absent. Such files are never represented as `clean`; submission snapshots preserve their unscanned status. Pending and rejected files remain blocked.

Normal deployments default both staging flags off. Even if an unscanned-upload flag is accidentally left on, staging mode must also be true before the service permits an unscanned download/submission. With staging mode off, unscanned files are inaccessible and cannot be submitted; normal uploads still use the existing ClamAV quarantine/clean/rejected flow. Scan/quarantine or remove staging files before promoting data into a normal deployment. OVH/S3 migration must retain private access and these checks; selecting object storage does not supply antivirus scanning.

The additive migration permits the new state without changing existing records. Its down migration refuses to remove that state while unscanned rows exist. Application rollback does **not** automatically reverse migrations.

## Runtime, email and resource limits

The six-container stack remains PHP-FPM (two on-demand children), small Nginx, PostgreSQL 16, Redis, one worker and one scheduler. Limits total **656 MiB**: app 224, database 160, Redis 48, worker 112, scheduler 80 and web 32. PostgreSQL uses 32 MiB shared buffers and at most 20 connections. Redis uses AOF, a 24 MiB data limit and `noeviction`. Services have restart policies and 5 MiB × 2 rotated Docker logs; the one-off migration tool is separately limited to 160 MiB.

Only web joins the shared `edge` network as `weavdash-staging-web`. Data services are on an internal network with no host ports. App/worker/scheduler additionally join a dedicated egress network for SMTP and R2 HTTPS. Runtime credentials use restricted `weavdash_app`; only migration tools get `weavdash_migrator`. Debug is disabled. Secure, HttpOnly database sessions, the application key and existing session identifier are preserved during the rename; new installations default to a staging-named cookie.

Email uses the previously verified Brevo SMTP configuration: SMTPS port 465, the existing login/key and verified sender in private raw-format `smtp.env`. App, worker and scheduler use `MAIL_MAILER=smtp`; normal explicit-send/consent/outbox authorization remains intact. Follow-up draft approval still does not send outreach. A single user-authorized invitation passed scheduler → Redis worker → SMTP → inbox delivery, and the user confirmed its public link worked. No additional real messages were sent for registration testing; local tests use captured mail.

The user confirmed Cloudflare's origin address and Full (strict) TLS. The existing wildcard origin certificate covers the unchanged hostname. Other applications' TLS/DNS configuration remains unchanged.

## Deployment and updates

Build on the workstation/CI, never on this VPS. Use a new revision tag and retain the previous deployed images. For a **new installation**, generate private files outside Git:

```sh
python3 src/scripts/create-staging-config.py /tmp/weavdash-staging-private \
    --url https://weavdash.cyrusmanatad.com --revision REVISION \
    --smtp-env /path/outside/git/smtp.env
docker build --target app -f src/docker/staging.Dockerfile -t weavdash-staging-app:REVISION src
docker build --target web -f src/docker/staging.Dockerfile -t weavdash-staging-web:REVISION src
docker save -o /tmp/weavdash-staging-images.tar weavdash-staging-app:REVISION weavdash-staging-web:REVISION
sha256sum /tmp/weavdash-staging-images.tar
```

The generator refuses to replace existing secrets. Transfer the bundle, Compose file, `src/docker/postgres/init.sh` and private configuration over SSH into the matching staging directory. Validate the destination checksum. Preserve directory/file permissions. For updates, keep the existing application key, database passwords and reviewer passwords; back up first and change only `STAGING_APP_IMAGE` / `STAGING_WEB_IMAGE` in `staging.env`.

From `/home/cyrusadmin/weavdash-staging`:

```sh
docker load --input staging-images.tar
docker compose --env-file staging.env -f compose.staging.yaml config --quiet
docker compose --env-file staging.env -f compose.staging.yaml up -d postgres redis
docker compose --env-file staging.env -f compose.staging.yaml run --rm -T migrate
docker compose --env-file staging.env -f compose.staging.yaml run --rm -T migrate php scripts/grant-runtime.php
docker compose --env-file staging.env -f compose.staging.yaml up -d
docker compose --env-file staging.env -f compose.staging.yaml restart web
```

Restarting web refreshes its private PHP upstream after application replacement. Use an uploaded script/normal SSH command for multi-step operations; piping a script into `ssh ... bash -s` can let Compose consume the following commands from stdin. Use `config --quiet` to avoid printing interpolated secrets.

The resource trial requires at least **256 MiB available host RAM**, zero OOM events and no sustained swap growth. Check host RAM/swap/CPU/disk, container memory and OOM/restart counters under several concurrent users. If thresholds fail, stop only this staging project, preserve its volumes, and reassess. Do not prune shared images, volumes, backups or other application containers. The earlier authorized cleanup removed only unused build cache older than seven days.

## Rename, proxy and recovery

The rename makes a cold copy of the original PostgreSQL and Redis volumes after stopping the old services cleanly. It also archives/copies local files, reuses all secrets and sessions, and checks business-row counts before/after migration. Original containers, volumes, images and `/home/cyrusadmin/weavdash-preview` remain stopped/archived for recovery; they are not the active staging environment. This legacy path is an operational rollback identifier and is intentionally not rewritten in history.

The cutover used a temporary `compose.alias-transition.yaml` until the root-owned shared Nginx upstream was renamed. The user completed activation; the override is now archived in `backups/` and the live service uses only the canonical staging alias. The candidate changes only the WeavDash upstream name. Validate it in the current shared proxy's network namespace, then the user runs the reviewed sudo activation script:

```sh
ssh -t cyrusadmin@216.219.85.134 \
    'sudo bash /home/cyrusadmin/weavdash-staging/activate-proxy.sh'
```

The script checks the live file's expected checksum, retains `backups/edge-before-staging-rename.conf`, writes in place because Nginx bind-mounts the file, tests and reloads; failures restore the previous file. Activation, removal of the compatibility override, canonical web recreation and all five HTTPS checks completed successfully. No extra public port is exposed.

Before every subsequent migration, back up **both database and uploaded files** together, plus the matching application key/configuration:

```sh
umask 077
cp staging.env backups/staging-before-update.env
cp smtp.env backups/smtp-before-update.env
docker compose --env-file staging.env -f compose.staging.yaml exec -T postgres \
    pg_dump -U postgres -d weavdash -Fc > backups/staging-before-update.dump
docker compose --env-file staging.env -f compose.staging.yaml exec -T app \
    tar -C storage/app/private -czf - . > backups/private-before-update.tar.gz
```

Keep a protected off-host copy for durable retention; no automated backup service is included. Restore the matching files and database together. Stop web/app/worker/scheduler before a reviewed database restore; use `pg_restore -U postgres --clean --if-exists -d weavdash` only inside the staging PostgreSQL service. Never target another application's database or volume.

For an application rollback, restore compatible prior image tags and matching Compose/config, then recreate services and refresh web. Never automatically reverse migrations. If data/schema compatibility is uncertain, keep staging stopped until a reviewed restore plan is ready. The original archived database is a point-in-time copy: restarting it after colleagues create new staging data would lose those later changes unless they are migrated back. Preserve current staging volumes before any rollback.

To undo the proxy rename, restore its backup **in place** with sudo, test and reload shared Nginx, and recheck all four other sites. Retain current data volumes and secrets throughout recovery.

## Verification — 2026-11-08

| Gate | Result |
| --- | --- |
| Formatting, TypeScript, component tests and build | Passed: pinned formatter, `vue-tsc`, 75 component tests and Vite production build. |
| Focused backend suite | Passed: 35 tests, 317 assertions. Includes all registration pages/validation, mail availability, disabled-upload override and double opt-in for unscanned files. |
| PostgreSQL onboarding integration | Passed: 6 tests, 114 assertions, including unscanned upload/download/submission, immutable snapshot labelling and denied download/submission with staging mode or opt-in off. |
| Normal scanner/isolation acceptance | Passed: 4 tests, 104 assertions; real ClamAV clean/EICAR/pending behavior, tenant isolation, expiry and capability revocation retained. |
| Existing desktop/mobile browser journeys | Passed: 4 journeys covering signup/verification, upload/download/removal, submission/correction and revocation, with accessibility checks. Captures use `/tmp`. |
| All three registration experiences | Full Partner, Brand and Professional signup → verification → correct portal provisioning passed locally using synthetic identities and captured mail. |
| Earlier email verification | Brevo authenticated; user confirmed the single requested invitation arrived and its link worked. |
| Rename/data preservation | Passed: cold volume copies, unchanged application/database secrets and reviewer credentials, preserved cookie/session identifier. Business counts matched exactly: 3 users, 3 workspaces, 6 leads, 4 onboarding records, 0 documents and 1 mail intent before/after the move. Three default workspace labels were updated without changing customized names. |
| Public staging journeys | Passed for all three experiences at 390×900 and 1440×900: login/logout, secure sessions, assets/navigation, no overflow or browser errors, all registration pages and validation, password recovery, pipeline edits, local unscanned upload/download/submission and missing-file rejection. |
| File persistence and access | Passed: uploaded bytes survived application-container replacement, matched on authorized download, and a fresh unauthenticated session was denied. Normal-deployment quarantine guards also passed in PostgreSQL tests. Owner review at 1440×900 displayed the unscanned label/download; a foreign workspace owner was denied with HTTP 404. |
| Current resource trial | Passed: 134 page visits in 60 seconds across three reviewers; slowest navigation 1.798 s, no browser errors. Available RAM minimum 668.8 MiB; swap growth 0.00 MiB; zero OOM events or container restarts. Approximately 23 GiB disk free. |
| Final proxy rename | Passed: user completed sudo activation, live Nginx validates, the compatibility override was archived, and web was recreated with only the canonical staging alias. All four existing sites return HTTP 200; staging redirects to login (302); TLS verification succeeds for all five. The preserved-file download passed again after proxy cutover. |

Live reproduction from `src/` uses a private credentials file. The verifier edits only synthetic reviewer data and creates a test onboarding submission with a small local reference file:

```sh
STAGING_CREDENTIALS_FILE=/path/outside/git/reviewers.json \
    STAGING_TEST_URL=https://weavdash.cyrusmanatad.com node scripts/verify-staging.mjs
```

Optional `STAGING_SCREENSHOT_DIR=/tmp/...` enables temporary captures; inspect and remove them. `STAGING_PERSISTENCE_FILE=/path/private/proof.json` stores a mode-600 capability session/reference for container-replacement checks; it must stay outside Git. No screenshots are retained by default.

Inherited limitations remain explicit: one competing-worker mail acceptance test errors in its probe-result JSON parser, reproduced against unchanged `HEAD`; the other 38 mail tests passed. Secret scanning flags two unchanged evidence-checksum false positives in `planning/evidence/m5/schedules/source-sha256.json:7` and `planning/evidence/pipeline-drag/source-sha256.json:10`; the synthetic positive control passed and rules were not weakened. These are not new staging regressions and do not advance production release acceptance.

Visual inspection: 390×900 guest upload and 1440×900 owner review captures show the existing cards, fields, buttons and focus styles, the Staging banner and clear unscanned-file labels without horizontal overflow. All new inspection screenshots were temporary and removed afterward. Reused components include `FoundationShell`, `FormField`, the shared button and confirmation dialog.
