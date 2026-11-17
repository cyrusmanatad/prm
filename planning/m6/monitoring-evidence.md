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

## Not verified

- Integration suite (`phpunit.pgsql.xml`), `rls.sql`, browser journeys and CI were not run.
- Alert mail through a real provider, the dead-man request against a real service, and behavior under a continuously running worker and scheduler.
- `compose.staging.yaml` changes (JSON log formatter, monitoring variables) are untested on staging.
