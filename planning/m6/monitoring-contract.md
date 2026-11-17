# M6-03 Monitoring Contract

Status: **first slice implemented, deployed to staging and partly exercised there (2026-11-17 UTC); M6-03 remains open.** Branch `feat/monitoring` (PR #34), based on `0adb9c7`. [Verification](monitoring-evidence.md). Source requirement: [operations](../shared/deployment-and-operations.md#recovery-observability-and-launch-gates) and [M6-03](../shared/remaining-tasks.md).

## Approach

**Proposal (implemented, awaiting confirmation):** monitoring runs inside the application rather than as a separate observability stack, which the [development host budget](../shared/deployment-and-operations.md#development-vps) excludes. The scheduler runs `monitor:check` every minute. It measures the signals below, keeps one alert state per check in PostgreSQL and announces each change by log line and, when recipients are configured, by email.

- **Fires once, reminds, recovers.** A check alerts after it has been breached continuously for its hold period, reminds every six hours while it stays firing (`MONITORING_REPEAT_SECONDS`), and sends one recovery notice. Evaluations between changes are silent.
- **Unknown is never healthy.** A heartbeat that was never recorded counts as breached. A signal that cannot be read (queue depth while Redis is down) keeps its last state; the Redis check reports the outage.
- **The check runs in-process in the scheduler,** because the scheduler discards the output of scheduled commands and would drop alert log lines.
- **Alert mail is sent directly by the scheduler,** not through the notification outbox or the queue, so a stopped worker or a blocked outbox cannot hold back its own alert. It uses `MONITORING_MAILER` (default: the transactional mailer).
- **Administration is unchanged (D33).** `/admin/operations` keeps the scheduler heartbeat only. Alert mail links to that page for diagnosis.

## Signals

Thresholds are **Proposals** for a small pilot, set in `src/config/monitoring.php` and overridable by environment. None is a measured capacity limit.

| Check | Measures | Alerts at | Hold |
|---|---|---|---|
| `worker_heartbeat` | Seconds since a worker last completed the heartbeat job the scheduler queues each minute. Covers a stopped worker and a queue too slow to reach the job (queue age). | 240 s, or never recorded | 2 min |
| `redis` | Redis reachable from the scheduler. | unreachable | 2 min |
| `queue_depth` | Pending jobs on the configured queue. | 200 | 5 min |
| `failed_jobs` | Failed jobs in the last hour. | 1 | none |
| `outbox_lag` | Age of the oldest notification that could be sent now (past its backoff, delivery active for its workspace). | 600 s | none |
| `outbox_uncertain` | Uncertain sends without an operator resolution. | 1 | none |
| `mail_transport` | Pending notifications deferred because mail or queue transport was unavailable. Stands in for mail-provider health. | 1 | 5 min |
| `delivery_budget` | Pending notifications deferred by a workspace or global delivery budget. Stands in for provider spend until real budget values exist (M5-09). | 1 | 10 min |
| `schedule_lateness` | Seconds the oldest enabled schedule is past due without being materialized or skipped. | 600 s | none |
| `upload_scan_backlog` | Uploaded documents still waiting for a scan result. | 1 | 15 min |
| `db_connections` | Connections to the application database as a percentage of `max_connections`. | 80% | 3 min |
| `disk_used` | Percentage used on the filesystem holding `storage/`. | 85% | none |
| `http_errors` | Responses with status 500 or above in the last five minutes. | 5 | none |
| `http_slow` | Responses slower than 2 s in the last five minutes. | 10 | none |

Request counts are per-minute Redis counters written after each response; `/up` is excluded.

## What watches the monitor

`monitor:check` runs on the scheduler, so it cannot report a stopped scheduler, a stopped host or its own failure. After every completed evaluation it requests `MONITORING_PING_URL`. An outside dead-man service that alerts when those requests stop is **required** for this design to cover scheduler, host, database and mail-path failure. The request carries no data and signals only that the evaluation ran, not that every check is healthy. **Assumption / Open:** which service; none is configured.

## Redaction and correlation

- **Monitoring data is aggregate.** `app_monitor_snapshot()` returns counts, ages and sizes only. Log lines and alert mail carry a check name, a number and a threshold; never a workspace, account, recipient, payload, job argument or exception message. The runtime role can call the snapshot without an operator session; that discloses only those totals.
- **Alert state is function-only.** `monitor_checks` has forced row security and no policy; the runtime role changes it only through `app_monitor_record`.
- **Correlation IDs.** Every request receives a server-generated `X-Request-Id`, added to the log context of that request and of the jobs it queues. A client-supplied ID is ignored.
- **Request logs.** Server errors and slow requests log method, route pattern, status and duration. The URL is never logged, because public capability links carry tokens in their path.
- **Not yet covered:** framework exception logs are unchanged. A database exception can still log a statement with its bound values. Redacting TINs, tokens, account references and document content from exception output remains open below.

## Configuration

| Variable | Purpose |
|---|---|
| `MONITORING_ALERT_TO` | Comma-separated alert recipients. Empty: alerts are logged only. |
| `MONITORING_PING_URL` | Dead-man URL requested after each evaluation. |
| `MONITORING_MAILER`, `MONITORING_REPEAT_SECONDS`, `MONITORING_HTTP`, `MONITORING_SLOW_MS`, `MONITORING_DISK_PATH` | Optional overrides; see `src/config/monitoring.php` for these and the per-check thresholds. |

`compose.staging.yaml` passes the first two through and switches staging logs to JSON lines. Deploying this needs the migration, `scripts/grant-runtime.php` and a restart of the worker and scheduler.

## Open before M6-03 can close

| Item | State |
|---|---|
| Alert ownership: who receives alerts, who responds, and out-of-hours expectations | **Assumption / Open.** Staging sends to one user-provided address; production recipients and response ownership are undecided. |
| Dead-man service and an external uptime check of `/up` | **Assumption / Open.** Needs a provider choice. |
| Exercise each alert and its recovery on staging, including real mail delivery | Partly done: deployed; seven of fourteen checks exercised (one by a real fault). Seven checks, reminders and inbox receipt remain. |
| Exception-log redaction | Not done. |
| Host memory, container restarts and database slow queries | Not collected; they need host-level or PostgreSQL configuration outside the application. |
| Scanner health | Only the scan backlog is observed. Staging runs without a scanner. |
| Provider spend | Only budget deferrals are observed; real limits and provider-side spend wait for M5-09. |
| Readiness-contract "storage" for object storage | Only local disk is measured. |
| Incident route and runbook | Belongs with M6-04. |
