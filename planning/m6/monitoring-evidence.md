# M6-03 monitoring verification — 2026-11-18

Scope: first monitoring slice on `feat/monitoring`, working tree based on `0adb9c7`. [Contract](monitoring-contract.md). Local verification only: no staging or production migration, deployment or external mail. **M6-03 remains open.**

Environment: disposable source-mounted Compose project `weavdash-admin` (PHP 8.5.11, PostgreSQL 16, Redis; no ClamAV, no long-running worker or scheduler). No UI changed.

| Gate | Result |
|---|---|
| Migration | `2026_11_18_000001_add_monitoring` applied, rolled back and reapplied; runtime grants reapplied. |
| Monitoring acceptance (runtime role, real PostgreSQL/Redis) | `MonitoringTest`: **8 tests / 99 assertions passed**. Covers function-only alert state under forced row security, hold/fire/remind/recover transitions, rejected invalid input and unknown observation sources, an aggregate-only snapshot, worker heartbeat (missing is breached), failed-job alert and recovery mail with no job content, log-only mode, the dead-man request and its failure, server-generated request IDs and request counters. |
| Full acceptance suite | **176 tests / 4,092 assertions: 171 passed, 3 skipped, 2 failed.** Both failures are in tests this change does not touch: `OnboardingSecurityTest` scanner rejection (this stack has no ClamAV) and `SyntheticRecoveryTest::test_smtp_disconnect_after_data_is_uncertain…` (its SMTP fixture process exits 1; it fails identically with this change stashed). Neither has been run on a stack where it is expected to pass. |
| PHP unit/feature | **42 tests / 362 assertions passed**. |
| Pint | Passed, 216 files. |
| PostgreSQL schema check | `scripts/check-postgres-schema.py` passed. |
| End-to-end run | `schedule:run` queued the worker heartbeat and ran `monitor:check`; a worker pass recorded the heartbeat; `monitor:check` then reported all fourteen checks, with alerts and recoveries emitted as JSON log lines when run with the staging log settings. |

## Changed with existing behavior

- `app_record_observation` now accepts `worker` as well as `scheduler`. `PlatformConsoleTest` used `worker` as its example of an unknown source; it now uses `scanner`.
- Every response carries `X-Request-Id`.

## Staging exercise — 2026-11-17 UTC

Deployed to staging as recorded in the [staging log](../shared/staging-deployment.md). Alert mail goes to one user-provided address through the existing SMTP provider.

| Exercise | Result |
|---|---|
| Baseline | All fourteen checks `ok` within two minutes of the update; scheduler and worker heartbeats recorded. |
| Real fault: worker stopped at 17:04 | `worker_heartbeat` fired at 17:10 (value 360 s: 240 s limit plus the 2-minute hold). Worker restarted at 17:10; recovered at 17:11. Both lines are in the scheduler's JSON log; no `monitor.mail_failed` was logged. |
| Alert path by one-off threshold override (16:54) | `failed_jobs`, `outbox_lag`, `schedule_lateness`, `disk_used`, `http_errors` and `http_slow` fired from a manual `monitor:check` run with their limits set to zero, and all six recovered on the next scheduled run. This exercises alert state and mail, not a real fault in those signals. |
| Defect found | With the staging log channel, the scheduler discards the output of scheduled commands, so alert log lines from the scheduled `monitor:check` were lost. Fixed in `3d5e593` by scheduling the check in-process; confirmed by the worker exercise above. The same loss still applies to the other scheduled commands' own output, which this change does not touch. |

Expected mail: 14 messages (7 alerts, 7 recoveries). **Receipt in the inbox is not confirmed here**; for the six override alerts and their recoveries, a mail failure would not have been visible in the logs captured.

Not exercised on staging: `redis`, `queue_depth`, `outbox_uncertain`, `mail_transport`, `delivery_budget`, `upload_scan_backlog` and `db_connections` (they need a sustained real fault or shared-data changes), reminders, and the dead-man request.

## Not verified

- Integration suite (`phpunit.pgsql.xml`), `rls.sql`, browser journeys and CI were not run.
- The dead-man request against a real service, and inbox receipt of alert mail.
- CI results for `3d5e593`.
