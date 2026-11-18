# Candidate release and recovery runbook

Date: 2026-11-18. Scope: M6-04 preparation for the [readiness candidate](readiness-evidence.md). **Prepared, not a production rollout or a completed deployment drill.** Existing staging is recorded separately in [deployment history](../shared/staging-deployment.md).

## Preconditions

- Record the exact candidate commit and matching app/web image digests, current images, migration list and effective configuration parity across app/worker/scheduler. Build outside the VPS. The Redis installer verifies the pinned official 6.3.0 source checksum and retries incomplete downloads.
- Close or explicitly resolve the current candidate's tests and dependency advisories. The unpatched picomatch advisory still fails the unchanged audit gate; a successful image build does not waive it.
- Agree the environment, recipient scope, provider-wide usage allocation, disabled paid overage and activation window. The [approved budget targets](../m5/sending-budget-proposal.md) do not authorize deployment or purchases. Zero in old per-minute configuration means unlimited/unconfigured, not disabled delivery.
- For live data, first resolve hosting/processing regions, privacy/retention, scanner requirements, operator ownership, recovery objectives and explicit rollout approval. Staging's unscanned-upload exception is not production acceptance.

## Backup and deployment order

1. Inventory active schedules and pending/sending/uncertain mail in both outboxes, recent failures and document references. Do not dispatch an unreviewed backlog or flush a shared queue. Reconcile an uncertain send by evidence, never by returning it to pending.
2. In the approved window, pause ingress and new scheduler/worker work for this application only. Allow active sends to finish within the recorded worker/SMTP timeouts; preserve unresolved sending reservations for later uncertainty reconciliation. Keep PostgreSQL/Redis and unrelated applications running.
3. Back up PostgreSQL, referenced document/object bytes and matching keys/configuration. Verify every referenced object's checksum and the backup inventory. Encrypt the bundle; hold decryption material separately; verify a protected off-host copy. The local rehearsal is not an off-host service or an R2 restore proof.
4. Deploy the reviewed app/web image pair. Through the migration role, apply migrations and refresh `scripts/grant-runtime.php`. Migration `2026_11_18_000004_add_mail_daily_budget` is additive, with **activation off**. Check forced RLS and denied direct runtime access on both budget tables; confirm old data counts/fingerprints, identities and permissions are preserved.
5. Start matching runtimes with reviewed configuration; refresh the web proxy's PHP upstream as required. Privately verify SMTP/origin/key/database/Redis/queue parity and business limits 1/workspace/minute and 2/global/minute. Never print environment secrets or a full resolved Compose configuration into evidence.
6. Verify HTTPS `/up`, sign-in, a controlled account's workspace/Brand counts, role-denied paths, mail state, heartbeats and asset parity. Use current synthetic controlled records for optional write smoke tests. Do not claim SMTP acceptance is inbox receipt.
7. Only after provider remaining allowance and shared consumers are reconciled, explicitly activate the database policy through the controlled migration job:

   ```sh
   php artisan mail:budget-policy --enable \
       --operator="${MAIL_BUDGET_OPERATOR:?Set the approved operator label}" \
       --reason="${MAIL_BUDGET_REASON:?Set the reviewed activation reason}"
   ```

   The command requires the migration role, writes an audit event, and prints aggregate counters only. It does not change the approved 250/200/50 limits or reset consumption. Record its state alongside a runtime snapshot. Verify all sending code uses this candidate before resuming traffic; older images do not enforce the new policy.
8. Resume only reviewed work. Observe cap counters, 80% usage warnings, errors, queue age, failures, pending/uncertain mail and resource headroom. A separate external dead-man/uptime path is required when SMTP or the scheduler itself cannot alert.

## Rollback and incidents

- Before activation/consumption, a reviewed migration rollback may be possible; inspect all newer migrations and data first. Do not assume `--step=1` still names the intended migration.
- Once active or consumed, the mail-budget down migration refuses. Keep the ledger and apply a forward fix. A rollback to older application images can bypass accounting: pause outbound-capable application processes and ingress before such a change, and keep them paused until a compatible fix or explicit revised operating decision exists.
- Do not delete receipts, adjust consumed counts, reset unknown sends or set rate limits to zero as an emergency stop. Stop the relevant application processes in a reviewed window; preserve history and recover current-basis checks before resuming.
- Budget exhaustion: review aggregate use, other provider consumers and deferred notices. Account/security allocation is reserved from business use. Do not auto-upgrade or automatically disable the cap. Escalate through the assigned owner; that production owner is still undecided.
- Restore a backup into an isolated recovery environment first, check database/object integrity and matching key decryption, then test restricted runtime isolation and file checksums. Keep sending processes stopped until provider outcomes and pending work have been reconciled.

## Remaining acceptance

Rehearse this sequence on the final candidate and chosen topology, including worker drain/restart, migrations, image/asset matching, controlled activation, fault/forward repair and external alert receipt. Agree RPO/RTO and test actual off-host/R2 restoration at representative size. Assign operators and escalation. These steps remain open under M6-01/02/03/04/06/08/09; this runbook alone closes none of them.
