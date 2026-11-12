# M5-10 synthetic recovery contract

**Confirmed:** implement synthetic recovery acceptance for all supported M5 workflows. Preserve the approved delivery policy: PostgreSQL reconstructs eligible pending work; sending/uncertain outcomes never automatically retry. This contract adds no public endpoint, schema or delivery permission.

## Disposable environment

`compose.recovery.yaml` is a standalone configuration, never an override for staging or development. It provides dedicated PostgreSQL/runtime and migration roles, persistent Redis AOF, private storage, queues, worker/scheduler and an internal-only local SMTP sink. It reads no application `.env`, publishes no ports and has no external network. Credentials are public synthetic fixture values used only on this isolated network. The source directory must be a separate disposable copy with installed dependencies and writable Laravel cache/storage directories.

`src/scripts/check-recovery.py` requires a fresh `weavdash-m5-recovery-<suffix>` project and refuses existing containers, volumes or networks for that project. Before each fault it inspects actual container project/service/marker labels, published ports, network ownership/internal status, volume ownership and bind paths. No Docker socket or Docker credentials enter the acceptance container. File requests in a temporary control directory let PHPUnit ask the host broker to inject a named fault. Invalid requests fail closed. The runner removes only its own project and volumes after completion or failure; never run Docker prune.

## Acceptance matrix

| Area | Required observation |
| --- | --- |
| Partner invitations | AOF restart retains a queued job; explicit queue loss preserves PostgreSQL intent; reconstructed work captures once. |
| Onboarding | Original/replacement invitations and lifecycle notices retain generation/version/recipient checks; lost jobs reconstruct and replay once. Obsolete invitation generations remain cancelled. |
| Reminder/digest | Durable schedule/run/notification stages reconstruct after queue loss; replay does not repeat completed business work or mail. Weekly downtime coalesces; deterministic clock tests retain gap/fold/fractional-offset policy. |
| Draft preparation/review/outreach | Ready runs prepare one private draft, review consent remains account-only, approved send uses the exact approved version. Queue/service restart cannot bypass reply, approval, consent or membership invalidation. |
| Access restoration | Cancelling obsolete work is terminal; restoring membership/consent does not revive it. Existing real PostgreSQL RLS and lock-order race tests remain mandatory. |
| Delivery controls | Real 60-second dispatch lease expires after lost publication; durable backoff and PostgreSQL workspace/global budget records survive Redis loss and fresh workers. Existing bounded fair ordering and budget serialization tests remain mandatory. |
| SMTP failures | Local service outage and disconnect after DATA produce sanitized uncertain outcomes; another eligible workspace can progress after service restoration. Actual worker SIGKILL during DATA and Redis reclamation cannot resend. Reconciliation and scoped audited operator resolution cannot make uncertain work retryable. |
| Processes | Actual worker/scheduler Artisan SIGKILL triggers automatic container restart. Existing process tests verify real mutex TTL and atomic business-action replay. |

## Verification and boundaries

Run the complete PostgreSQL/Redis acceptance suite, PostgreSQL integration suite, PHP unit/feature suite, changed-PHP Pint and fault-target guard tests. UI gates apply if application UI changes; this slice modifies backend acceptance infrastructure only. Retain concise text evidence with revision, commands, timings, fault observations and unresolved gates in [recovery evidence](recovery-evidence.md). No new screenshots or raw message captures belong in the repository. SMTP captures are synthetic and disappear with the disposable volume.

M5-09 provider trials and receipt/link-opening acceptance, approved production rate/daily/spend limits, remaining administration and inherited M5/M6 release gates stay open. Local SMTP acceptance proves no external receipt. No shared data migration, external mail, operator provisioning or deployment is authorized by this work.
