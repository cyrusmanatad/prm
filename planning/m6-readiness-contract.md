# M6 Pilot and Production Readiness Contract

Status: **preparation started; implementation and acceptance pending** on 2026-10-23. The user requested committing the current work, advancing toward M6 where possible and retaining all unfinished work in the handoff. The [roadmap](implementation-roadmap.md) still requires M1–M5 for full M6 acceptance. [M5 remains incomplete](m5-status.md).

## Entry and scope

Preparation now consists of this dependency/evidence contract, the [remaining-task register](remaining-tasks.md) and [implementation handoff](implementation-handoff.md). No M6 operational drill, monitoring deployment, MFA implementation, provider selection or launch acceptance is claimed by these documents.

**Confirmed:** D18 production MFA, D19/D20 carried-forward manual reviews, D25 required transactional email and the mandatory [UI standards](../AGENTS.md#mandatory-ui-standards-for-every-milestone) still apply. The latest request preserves unfinished/deferred work; it does not mark M5 complete or approve provider purchases, live data, external trial messages or production deployment.

**Proposal / execution plan:** independent preparation and synthetic local rehearsals may proceed alongside required M5 completion. Full product UAT and final candidate acceptance follow completion of their feature dependencies. Business choices, recovery objectives, pilot thresholds and host/region/provider decisions remain **Assumption / Open** until explicitly resolved.

## Work packages and acceptance

| Work package | Tasks | Ready to begin | Completion evidence |
|---|---|---|---|
| Recovery | M6-01 | Define objectives and rehearse with isolated synthetic database/documents; coordinate production storage/key decisions. | Database plus document restore, key/secret recovery, integrity and tenant-boundary checks; measured recovery against approved objectives. |
| Hosting and capacity | M6-02, M3-01 | Define a representative pilot load, inventory services and measure development resources. | Approved budget/host/regions/storage and measured peak/load behavior including scanner, queues and storage; no assumed capacity from the development machine. |
| Observability | M6-03 | Specify metrics, redaction and alert ownership using current app/queue/outbox states. | Implemented monitoring and demonstrated alert/incident routes for application, DB, storage, queue, scheduler, outbox and upload failures. |
| Delivery operations | M6-04, M6-06 | Draft runbooks and rehearse approved local operations. | Tested release/migration/worker/scheduler/rollback-forward-repair procedures and assigned operator/support/incident responsibilities; secure runtime and secret boundaries. |
| Identity and privacy | M1-04, M3-02, M4-02, M6-05 | Audit the current implementation and specify policies; implement within authorized development scope. | Enforced production MFA, appropriate operator controls, end-to-end privacy/export/deletion/retention behavior, safe uploads and closed critical security findings. |
| Product and delivery acceptance | M5-01–M5-10, M6-07, M6-08 | Complete required M5 workflows; reuse existing tests and preserve prior evidence. | Required email flows and scheduling/approvals work without mailbox syncing/AI; approved provider trials, current full-stack tests and pilot UAT pass. |
| Manual acceptance and launch | M0-01/M0-02, M1-01–M1-03, M2-01, M6-09/M6-10 | Prepare reviewer scripts and decision inventory now. | Actual manual reviews, pilot results and final documentation are recorded; applicable live-data/commercial gates and explicit rollout authorization are obtained. |

Task IDs refer to [remaining-tasks.md](remaining-tasks.md); that register holds current statuses and originating sources. Optional AI/mailbox syncing, real tax issuance, automated transfers and later M7–M9 features do not become M6 prerequisites merely because they are tracked.

## Evidence and completion rules

For each completed work package retain the candidate commit, environment/tool versions, sanitized command/result, reviewer/operator where applicable, and relevant logs/screenshots/measurements. Separate implemented behavior from configuration, local capture from delivered mail, Linux WebKit from native Safari, and synthetic rehearsals from approved live operation.

Reuse [M5 local evidence](evidence/m5/README.md) as baseline evidence for its recorded scope. Run new or affected acceptance checks when implementation changes; do not claim earlier remote runs verify the current candidate. UI changes require shared component/design review, pinned Prettier, types/build, relevant interactions/browser checks, inspected desktop/mobile screenshots and handoff evidence.

M6 stays incomplete while required predecessor features or applicable release gates remain open. Keep unresolved items visible under their stable IDs even when work shifts between milestones. Any accepted scope reduction must record an explicit decision, its effect on pilot/launch behavior and the task's future destination. Production rollout remains a separate authorized action after the result is concrete and reviewable.
