# M5-09 preflight and delivery evidence

Date/base: 2026-11-14 (Asia/Shanghai), `c13e960`, branch `m5/provider-delivery-acceptance-2026-11-14`. Scope: [matrix, budget proposal and runbook](provider-delivery-acceptance.md). **Staging update deployed; P01/P10 receipt and links pass. P11 reset/login is resolved by explicit user confirmation; remaining delivery rows are pending. Production budget approval remains open.**

## Preparation findings

- `git status --short --branch`: clean `main`; `git log -4 --oneline` confirmed `c13e960` merges PR #16. Created the branch from that revision. Planning merge-pending references updated; historical test results retain their original scope.
- Read the remaining-task register, email requirements, delivery operations contract/evidence, recovery evidence, staging email history, Compose and mail/queue configuration, mail templates and existing notification acceptance tests. Brevo/SMTPS 465 is historical configuration, not newly verified provider access or receipt.
- Initial “Yes” supplied no concrete recipients. Subsequently the user provided one controlled inbox and the existing HTTPS staging origin, then explicitly approved 13 expected messages, maximum 20 attempts total/per UTC day, business reservation limits 1/workspace/minute and 2 globally/minute, with zero additional spend. A further confirmation attests available Brevo allowance with no paid overage/automatic upgrade; the user then clarified **300/day, zero used today**. The trial ceiling remains 20. Keep full recipient address in private trial configuration only. External sends remain zero at this preflight.
- Read-only local container check: `weavdash-professional-app-1` (project `weavdash-professional`), `weavdash-worker-1` and `weavdash-scheduler-1` (project `weavdash`) each report `MAIL_MAILER=array`, no cached Laravel config, and unset workspace/global notification-limit environment variables. Application defaults are zero/unconfigured. This is no proof that their code/database/queue configuration matches; do not use them as an approved provider trial stack.
- Found `compose.mail-validation.yaml` selected SMTP only for app. Changed its explicit opt-in override to share SMTP selection across app/worker/scheduler; default Compose still uses `array`. No service restart, migration, shared-data change, environment edit or deployment performed.

## Checks

`docker compose --env-file /dev/null -p weavdash-m5-provider-config-check -f compose.yaml [-f compose.mail-validation.yaml] config --format json`, rendered with synthetic required password variables and `TRANSACTIONAL_MAIL_MAILER=array`: passed. Assertions verified base `array` and trial `smtp` for app/worker/scheduler, parity of selected mail/origin/database/Redis/budget fields, and retained Redis queue configuration. No containers started and no SMTP connection made. Runtime parity was unverified at preparation; post-deployment checks below now confirm it.

Local-link validation: **408 links checked, zero missing targets** across changed and new Markdown. `git diff --check`: passed. Pinned Prettier (`src/node_modules/.bin/prettier --check ../compose.mail-validation.yaml`, from `src/`): passed. Matrix review accounts for the second submission notice after correction: **13 expected messages**, within the proposed 20-attempt ceiling. No frontend/application business logic changed; UI/type/build/browser acceptance is outside this configuration/documentation change. Existing recovery and CI evidence remains in [recovery results](recovery-evidence.md) and [CI repair](ci-repair-evidence.md), merged through PR #16.

## Staging compatibility preflight

Read-only SSH checks found matching effective app/worker/scheduler SMTP, origin, key, database, Redis and sender settings; budget config is absent in all three. All backend services use `20261109-r2`. Source hashes show older NotificationOutbox/FollowUpDrafts and Composer lock; the three `2026_11_09` migrations are absent and `config/notifications.php` is missing. Runtime `migrate:status` was denied access to `migrations`, as expected for the restricted role; a subsequent read-only PostgreSQL administrative query established applied migration names without changing permissions. No pending/sending/uncertain outbox rows, zero failed jobs, one historical sent invitation, two documents. The approved inbox has no existing account. No shared records were edited.

Prepared matching candidate app/web images `c13e960-providertrial` using the pinned staging Dockerfile and current lockfile. Both builds passed; container smoke check reports Laravel 13.40.0, matching NotificationOutbox/FollowUpDrafts/config/lock hashes and no config cache. A no-network web smoke check verifies its asset manifest matches the app image. Host-generated assets differ and are excluded from the Docker build context. Candidate images are now deployed under the explicit user authorization. Both images are exported in a mode-600 local archive; SHA-256 `3a545fc9670d582a7aab9428fe2494e068b5b2866cbc7d8af39583b744111b58`. Final reference validation checks **411 local links**, zero missing targets; `git diff --check` passes.

Added `compose.staging-mail-trial.yaml`: SMTP disabled by default (production business outbox defers array), optional authorized SMTP, approved limits 1/workspace and 2/global on app/worker/scheduler. Synthetic Compose renders pass in both modes for mail/origin/rates/runtime database role/Redis/image assertions; pinned Prettier passes both changed YAML files. See the [prepared update, backups and migration plan](provider-delivery-acceptance.md#prepared-staging-compatibility-update--authorized). **Confirmed:** user explicitly authorized deployment on 2026-11-14. Trial-mail authorization does not need repeating. The authorized update is deployed; results follow.

## Authorized deployment — passed

Candidate image bundle transferred and checksum verified. Host preflight: approximately 755 MiB available RAM and 22 GiB disk free. Paused only staging web/app/worker/scheduler; database/Redis and other sites remain running. Baseline: 4 users, 4 workspaces, 6 leads, 7 onboarding requests, 2 document records, 1 historical sent notification, no schedules/drafts and no pending/sending/uncertain intent.

Backups: private remote `backups/m509-20261114-081143` with database dump, matching configuration/keys/Compose and referenced attachments. Reverse copy and independent R2/local verification both passed: **2 documents / 96 bytes**. R2 originals preserved. Protected off-host copy under `/tmp/weavdash-m509-private/backups/` verified against all eight remote file checksums before migration. No record cleanup, shared queue flush or permissions weakening.

All three additive migrations passed (approval invalidation, delivery controls, follow-up delivery) and runtime privileges were reapplied through the migration tool. All baseline counts above matched immediately after migration. Recreated app/worker/scheduler and web with matching `c13e960-providertrial` images. Effective configuration equality passed across all three, initially in `array`, with limits 1/workspace and 2/global and correct HTTPS origin; no OOM events.

Live Chromium checks: **6 reviewer logins / 14 pages**, **390×900 and 1440×900**, zero page errors or horizontal overflow. Owner-authenticated retained R2 download matches private expected bytes; anonymous download is denied. Initial probe used an expired November 9 guest session and received the expected 403; fixed the probe to use the current authorized owner rather than weakening session lifetime. A new synthetic workspace requires the brand reviewer to choose its original workspace at login; the smoke probe now handles that supported selector.

The initial staging business capture probe was incorrect for `APP_ENV=production`: the worker safely deferred with `transport_unavailable` instead of claiming capture or sending. Revoked only that new synthetic invitation through the normal tenant endpoint; dispatcher subsequently shows **cancelled**, with zero external attempt. Production/local transport guard is unchanged; local/testing remains the correct place for queued array capture. A separate new controlled-inbox account was registered with captured account mail and remains unverified until P10. Its password/session stay in mode-600 private temporary files; no existing account password changed.

SMTP restoration passed effective parity across all three deployed backend services: same image/key/origin/mail/sender/database/Redis/queue settings, SMTPS 465, configured credentials, no MAIL_URL override, limits 1/2 and no OOM. Before the first external request, pending/reserved/uncertain and failed jobs were zero. Actual local Laravel configuration recheck still returns `array` for app, worker and scheduler; local settings were never changed.

P01's redacted server timing (UTC): intent **08:27:05**, reservation **08:28:03**, SMTP acceptance **08:28:05**, queue monitor subsequently **0 pending / 0 delayed / 0 reserved**. No direct mail probe or manual delivery call was used. P10 retained the ordinary user-triggered verification path. The first receipt report preceded server verification; after follow-up, user confirms the workspace opens and the server confirms verified identity and completed workspace provisioning. User also confirms P01 receipt/explicit acceptance; database shows the invitation accepted and partnership active. P10 and P01 receipt/link rows now pass. No uncertainty occurred and no send was replayed.

## Password reset — resolved by user confirmation

**Confirmed (2026-11-14): resolved.** The user explicitly instructed marking this item resolved and confirmed resetting their real password and successfully logging in. P11 receipt/reset/login is accepted on that manual confirmation. The chosen password is private; the temporary trial credential is superseded and must not be retried. Do not request the chosen password in chat, reset the account directly, bypass login or send more reset mail to investigate this closed item.

Earlier automated observations concerned the temporary trial credential and an unused token at the time of those checks; retain them as historical diagnostics, not an active blocker or a claim about the user’s current login. No new automated server verification or external token-replay check is claimed by this resolution. P02–P09 delivery rows remain pending; the existing trial authorization and ceiling remain valid. Continue those workflows with an appropriate authenticated test session without reopening P11.

Current-source focused recovery regressions pass in the PHP 8.5.11 CI tool image with network disabled: **3 tests / 11 assertions** covering generic recovery response, invalid/reset/replayed token, session invalidation and expiry. Host PHP 8.3 cannot load the current dependencies; used the existing tool image instead. Reproduction from repository root:

```sh
docker run --rm --network none --user 1000:1000 -v /home/cyrusadmin/weavdash/src:/var/www/html -w /var/www/html weavdash-ci-repair:laravel1340 vendor/bin/phpunit --do-not-cache-result --filter 'test_password_reset_invalidates_tokens_and_all_database_sessions|test_expired_password_reset_tokens_cannot_change_passwords|test_recovery_has_a_generic_response_for_unknown_accounts' tests/Feature/Auth/AuthenticationTest.php
```

Final staging runtime/queue check passes: matching SMTPS configuration/images and trial rates, no OOM, no pending/sending/uncertain business intent and no failed jobs. Local app/worker/scheduler remain `array`. No new screenshots retained. This is an incomplete provider trial, not M5/M6 acceptance.

## Commit verification

Pinned Prettier passes both changed Compose YAML files; `git diff --check` passes; **423 local Markdown links** resolve to existing file targets. `python3 src/scripts/check-secrets.py` passes its synthetic positive control and scans **1,158 reviewable files with no unreviewed findings**. Private trial configuration, passwords, tokens, backups, images and scripts remain outside Git. No application/UI source changed in this commit; deployment and focused interaction/regression results are recorded above.

## External run ledger

| Gate | Result |
| --- | --- |
| Explicit provider, inbox, origin and concrete scope/limit approval | Confirmed by user on 2026-11-14; no repeat approval needed for this scope |
| Actual provider allowance, daily/spend controls and production budget | Trial allowance/no overage user-confirmed: 300/day, zero used today; the trial remains capped at 20. No account API available for independent verification. Production values remain unapproved. |
| Matching app/worker/scheduler effective configuration | Passed after SMTP restoration: matching images, key/origin/mail/sender/database/Redis/queue/limits; SMTPS 465, credentials present, MAIL_URL absent, limits 1/workspace and 2/global, no OOM. |
| P01–P11: 13 queued/account messages, provider acceptance and actual receipt | P01 partner invitation and P10 verification pass user receipt/link confirmation and server state. P11 receipt/reset/login resolved by explicit user confirmation. P02–P09 remain unexecuted. |
| Actual received-link opening and negative local regressions | P10 receipt and actual verification confirmed by user and server. P01 receipt and explicit acceptance confirmed by user; server shows accepted invitation and active partnership. P11 resolved by explicit user confirmation of actual password reset and successful login; earlier temporary-credential checks are historical. External replay was not rechecked. Other link/negative gates remain open. |
| Cancellation / uncertain-send protections | Unchanged; prior recovery/operations evidence retained; no new trial claim |
| Trial attempt count / incremental spend | **3 / 20** agent-initiated attempts: P10 account verification, P01 partner invitation and P11 password reset. Existing user-confirmed allowance; no purchase/upgrade. SMTP cannot independently verify billing/actual mailbox receipt. |
| Local capture | Rechecked effective Laravel config after deployment: local app, worker and scheduler all `array`. They were never switched to SMTP. Shared staging restored to its standing SMTP mode with trial rate limits; the deferred synthetic probe is cancelled. New queued local capture proof not run. |

Retain only recipient aliases, redacted correlations, timestamps, outcomes and bounded counts when filling this ledger. No raw MIME, addresses, credentials, capability URLs, tokens, passwords or new screenshots. M5-09 remains open; M5/M6, administration, broader UI/manual acceptance and inherited release gates are not accepted.
