# Deployment and Operations

## Development VPS

**Confirmed constraint:** resource-constrained OVH VPS is for development; production hosting will likely change. CPU/RAM will be selected for project needs; no VPS plan is purchased or capacity measured here. Initial recommendation: **2 vCPU, 4 GB RAM, approximately 80 GB SSD**, with uploaded documents stored externally. OS, region, provider plan, and budget remain open. Do not assume the current workspace machine represents the VPS or claim tested capacity.

**Approved stack, proposed topology:** Docker Compose with TLS reverse proxy serving built Vite assets and forwarding PHP requests to Laravel/PHP-FPM, one Laravel queue worker, a Laravel scheduler process, PostgreSQL, and Redis. Reuse one application image for PHP-FPM, worker, scheduler, and controlled migration jobs. No permanent Node process or separate frontend service is required without Inertia SSR. Use sandbox provider accounts, synthetic data, outbound-email restrictions, and no live payout credentials. Database/Redis ports stay private. Restrict SSH, enforce patching, rotate credentials, and expose only needed HTTP(S) ports.

Illustrative budget for a roughly **4 GiB / 2 vCPU development host**, subject to measurement: PostgreSQL 1 GiB, Laravel/PHP-FPM 640 MiB, one queue worker 384 MiB, scheduler 128 MiB, Redis 256 MiB, and proxy/static assets 128 MiB; leave roughly 1.5 GiB for OS, cache, peaks, and headroom. Tune PHP-FPM child count and worker memory/restart limits to observed usage. These are trial budgets, not benchmarked sizing guarantees. Prefer 8 GB RAM for multiple active developers or heavier document/import jobs; final sizing follows measurement. Keep sufficient SSD space for database growth, container images, logs, and temporary uploads even with external object storage.

Build PHP/application images and Vite assets in GitHub Actions, not on the VPS. Composer and frontend dependencies are installed during a later authorized build, not during planning. Bound connection pools and queue concurrency; schedule heavy exports/scans and cap upload sizes. Log rotation, disk alerts, database growth, and registry/image cleanup matter as much as CPU. Avoid self-hosted Supabase's full suite, identity servers, e-sign servers, AI models, search clusters, and heavyweight observability on this machine. Private uploads use Laravel's filesystem abstraction. AWS S3 and OVH Object Storage are candidates; provider, region, pricing/transfer costs, credentials, and purchase remain open. Test S3-compatible endpoints, signed URLs, multipart uploads, lifecycle/deletion, encryption, and backup/versioning needs before selection. Use least-privilege credentials and tenant-authorized access; keep buckets private. Local development storage is acceptable for synthetic fixtures with explicit backup limits.

## CI/CD proposal

GitHub Actions runs checks and builds immutable images; publish to a restricted registry such as GHCR. Pin third-party actions by reviewed commit SHA and images by digest; use least-privilege credentials. Deploy environment-specific configuration separately from images and keep secrets out of Git/build layers. See [GitHub publishing guidance](https://docs.github.com/en/actions/tutorials/publish-packages/publish-docker-images).

Run Laravel migrations as a dedicated controlled job with separate DB credentials; application containers use restricted roles. Build/cache configuration per environment without baking secrets into images. Deploy matching PHP code and Vite asset manifests; drain/restart Laravel queue workers so they load the new release, and ensure only the intended scheduler is active. Verify tenant-context cleanup across repeated worker jobs. Prefer backward-compatible expand/migrate/contract changes. Take verified backups before risky migrations. Rollback the image when safe; a database change may require forward repair or restore, not an automatic down-migration. Readiness, liveness, smoke checks, and documented brief maintenance windows are adequate initially; no zero-downtime promise.

[Docker Compose supports a single-server production configuration](https://docs.docker.com/compose/how-tos/production/), but a single host is still a single failure domain. Its use here is primarily development simplicity.

## Production transition

Select hosting after pilot load, budget, region, and data-processing requirements are known. Prefer managed PostgreSQL with recovery support and private object storage; move Laravel web and queue processes independently using the same application image. Redis may be managed or isolated. Preserve native Laravel users and session controls; enterprise SAML/OIDC can later link external identities to internal users without requiring an authentication-provider migration at launch.

Singapore is a mockup recommendation, not an approved residency requirement or a measured latency conclusion. Confirm OVH/other-provider regions and cross-border processing needs. Kubernetes is unnecessary until operational requirements justify it.

## Recovery, observability, and launch gates

Proposal: encrypted daily development backups off-host and a tested restore before pilot use; development data can be regenerated when synthetic. For production, agree RPO/RTO and then provision backup frequency/PITR, object versioning, recovery environment, and staffing to meet them. Do not promise production recovery using daily-only snapshots by default.

Monitor API error rate/latency, DB connections/slow queries, disk/memory, queue age/failures, outbox lag, scheduled-job lateness, upload-scan backlog, notification outcomes, and provider spend. Use correlation IDs and redact TINs, tokens, account references, and document content. Begin with lightweight structured logs and external uptime/error collection; add product analytics only after consent/privacy decisions.

Before live data: restore database plus documents, test tenant isolation on deployed roles, validate secret recovery/key rotation, review public uploads, exercise export/deletion, define incident response/support ownership, and measure a realistic pilot load. No production SLA, capacity target, or monthly cost is asserted without these inputs.

**Platform administration operations (implemented; local verification only).**

- **First owner and recovery are offline.** The first platform owner is created with `php artisan platform:grant {user} --permission=platform.owner --reason=… --operator=…`, run with the migration role through the controlled `migrate` job. The command refuses any other database role, so the web, worker and scheduler services cannot run it. `--recover` resets an operator's authenticator and `--revoke` removes a grant; each action deletes that identity's sessions, writes an audit row carrying the operator label and cannot remove the last usable platform owner. Later operators are managed by a platform owner at `/admin/operators`.
- **After migrating, re-run `scripts/grant-runtime.php`** through the same job so the runtime role can execute newly added functions.
- **The scheduler must be running** for the administration heartbeat; without it `/admin/operations` reports scheduler state as unknown.
- **What `/admin/operations` observes:** the latest scheduler heartbeat and its age, whether Redis is reachable and the pending depth of the configured queue (unknown when Redis cannot be read), the notification outbox backlog and unresolved uncertain sends, and failed jobs with only a job class and exception class. **It does not observe** worker liveness, scanner health or mail-provider health, keeps no history and sends no alerts.
- This is a diagnostic page, not monitoring or alerting. Monitoring is separate: a scheduled `monitor:check` with email/log alerts and a dead-man request is implemented and running on staging, not production ([monitoring contract](../m6/monitoring-contract.md)). M6-03 stays open.

**Confirmed (D33):** the two founders are the platform owners. Staging holds both founders' owner grants (2026-11-17); the second founder's is not usable until an authenticator is enrolled. **Assumption / Open:** production provisioning and a rehearsed bootstrap and recovery on a disposable environment (handoff item B13). No production operator has been provisioned.

## Readiness candidate follow-up (2026-11-18)

**Locally verified:** source-offline encrypted database/document/key/active-mail-ledger restore, safe framework/new-failed-job exception metadata and active mail-allocation warnings. These checks add no production recovery or alert acceptance. Historical failed-job payloads and custom logs remain review work. [Readiness evidence](../m6/readiness-evidence.md) records limits.

The [prepared release runbook](../m6/release-runbook.md) covers worker drain, protected backups, matching app/web images, migration-role schema/grants, inactive-by-default budget installation and explicit audited activation. Preserve the consumption ledger after activation; older sending images bypass accounting, so pause sending before any such rollback and use a compatible forward fix. Provider-wide/billing/operator inputs, actual chosen-topology drills and rollout approval remain required.


## Private export operations (PRIV-02, locally implemented 2026-11-20)

Apply `2026_11_20_000001_create_privacy_exports` and refresh runtime grants through the migration job before running the matching app/scheduler candidate. App and scheduler need the same private local export storage at `storage/app/private/privacy-exports`; existing Compose uses its shared private-storage volume. Verify this explicitly on a multi-host topology. Archives are not public files or onboarding R2 objects. The directory/file modes are 0700/0600.

`php artisan privacy:purge-exports` is scheduled every five minutes. It removes eligible final and interrupted ZIPs, up to 500 receipts per pass, with advisory-lock exclusion and absence checks; only successful file cleanup marks a receipt expired. Failures retain the receipt for retry, return command failure and emit aggregate warning `privacy.exports.cleanup_failed`. A running scheduler/accessible shared storage is required for physical cleanup; download checks independently deny expired archives. Review disk usage/backlog and warnings operationally.

After the first request, rollback refuses to discard receipts/audit/cleanup authority; preserve tables and fix forward. Backup copies of private archives remain under the approved recovery/retention work and are not erased by the export purge. This local slice does not validate a chosen production topology, production backup lifecycle or external processors. See [contract/limits](../m3/privacy-contract.md#delivered-priv-02-export-interface) and [evidence](../m3/privacy-evidence.md).
