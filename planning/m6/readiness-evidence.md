# M5/M6 continuation verification

Date: 2026-11-18. Branch: `fix/m5-m6-readiness-2026-11-18`, based on `0ebf3e7` (main application at `39520b4`). Scope: approved mail-budget targets, controlled staging email trials, seeder concurrency, CI repairs, exception redaction and isolated encrypted restore. No new application deployment or production operation.

## Changes and verification

| Work | Result |
| --- | --- |
| Seeder concurrency | Locks all manifest partnerships in ID order before source leads. Actual process blocking proves both orders: prior revocation refuses all writes; a waiting revocation withdraws the committed new registration. |
| Combined PostgreSQL/Redis acceptance | **84 tests / 2,257 assertions passed**: Brand visibility/approval, monitoring, budget, outbox, lifecycle, delivery operations and PresentationDatasetTest. A subsequent final budget run verifies the minute-slot fairness change; counts below. Dedicated disposable databases/runtime and migration roles, separate Redis prefix. |
| Daily budget | **13 tests / 105 assertions passed**: forced RLS/runtime denial, concurrent final slot, independent commits/outer rollback, 200 business/50 reserved allocations, rolling midnight safety, single-use receipts, conservative failed/unused charging, outbox deferral/recovery, minute-slot fairness, capped auth feedback, loopback SMTP versus capture, duplicate envelope recipients, unsupported transport refusal, 80% warning and rollback refusal. |
| Approved targets | 250 total attempts, max 200 business/50 reserved, 1 business/workspace/minute and 2 globally/minute. Rolling 24-hour enforcement also bounds every UTC day. Policy defaults inactive; only migration-role audited command activates it. No runtime policy editing or receipt refund. [Contract and remaining provider inputs](../m5/sending-budget-proposal.md). |
| Framework and failed-job exceptions | Safe class/source/validated SQLSTATE only; no message, SQL/bindings, context or trace arguments. New database-UUID failed-job exception strings use the same sanitizer. Job payload retained for recovery; historical rows are untouched. |
| PHP feature suite | **46 tests / 388 assertions passed**, including exception redaction 3/16 and failed-job redaction 1/10. |
| Frontend | Vite **8.3.8**, Vitest **4.1.12**, Laravel Vite plugin **3.2.1**. Changed-file formatting, types, **147 component tests** and production build passed. vue-tsc remains pinned 3.2.9; a trial upgrade exposed unrelated typing changes and was reverted. |
| CI backend/fresh setup | Main run `44268000650` showed a truncated PECL redis 6.3.0 archive and globally counted presentation fixtures. Both Dockerfiles now use bounded curl retry plus the official SHA-256 before local installation; the presentation test scopes counts to its manifest/batch. CLI and staging-web candidate image builds pass; Redis reports 6.3.0 and both asset manifests have SHA-256 `32b055cd643abccf4e43dbad1ce1e854da57beee7ec5612ee69deb312d473767`. No remote CI pass is claimed for this unpushed candidate. |
| Dependency audit | Still **13 findings (9 high, 4 moderate)**. [Picomatch advisory](https://github.com/advisories/GHSA-fc5q-39xc-vjpf) affects all published versions through 4.0.7 with no patched version at this check. Glob/minimatch remain in the compatible Vue test-utils formatting chain. No suppression, downgrade, gate weakening or fork. |
| Email provider trials | P02–P09 receipt/link journeys pass on deployed `39520b4`; P01/P10/P11 retain prior acceptance. 11 new sends + 3 historical = 14 actual attempts; 16/20 conservative ledger slots. Synthetic optional mail/schedules disabled. [Full redacted matrix and cleanup](../m5/provider-delivery-evidence.md#november-18-completed-trials). |

Capped signup creates the account and truthfully reports paused verification; resend returns the existing shared 503 page, and password reset retains generic known/unknown-account feedback. The verification screen reuses FoundationShell, shared fields/buttons and current auth styling; only the delivery-dependent sentence became a neutral email label.

Local Chromium checks at **1440×900 and 390×900**, with overflow checks also **1024/768/320 px**, passed paused signup, keyboard resend and 503 feedback, with **zero Axe violations**. Screenshots inspected for desktop/mobile card spacing, typography, neutral label, feedback and responsive error layout; temporary captures removed. These are scoped checks, not full manual accessibility or native Safari acceptance.

### Reproduction and limits

Run PHP commands in the pinned PHP 8.5 Docker image from `src/`; host PHP is older. The PostgreSQL run used a fresh migrated disposable database with `scripts/grant-runtime.php`, restricted `weavdash_app`, migration credentials supplied privately, existing isolated Redis service and unique prefix. Filter:

```sh
vendor/bin/phpunit -c phpunit.acceptance.xml --filter 'BrandLeadVisibilityTest|MonitoringTest|MailBudgetTest|BrandApprovalTest|NotificationOutboxTest|OnboardingNotificationTest|OnboardingLifecycleTest|DeliveryOperationsTest|PresentationDatasetTest'
vendor/bin/phpunit --testsuite 'Unit,Feature'
npm run format:check:changed
npm run typecheck
npm test
npm run build
```

Use a fresh database for the combined presentation suite; do not seed or clean shared data. Final changed-PHP Pint (23 files plus the final test update), Compose formatting, local documentation targets and `git diff --check` passed. Source secret scanning passed its positive control and checked 1,328 reviewable files without unreviewed findings. An initial harness run had seven Redis hostname errors; correcting the temporary runner resolved them. The final budget-only run also checks fairness after the combined run; the last PHP change afterward is Pint formatting only.

## M6-01 encrypted restore rehearsal

From repository root, with Docker/GPG and a compatible local PHP image; dependencies must exist in `src/vendor`:

```sh
python3 src/scripts/check-backup-restore.py \
    --project weavdash-m6-restore-20261118e \
    --image weavdash-readiness-app:20261118
```

The runner refuses pre-existing project resources. It uses two separate PostgreSQL containers/volumes, separate document volumes, an internal network, no published ports, read-only source and generated synthetic credentials/key. No worker, scheduler or SMTP is started.

Seeded two tenants, two documents and an active budget with one business/one reserved attempt. GPG AES-256 encrypted database/documents/matching application key into a bundle with separately held passphrase; verified an independent bundle copy, deleted plaintext inputs and stopped source PostgreSQL. Restored to the other database/document volume using the encrypted copy.

- All **68 public tables** matched exact row counts and canonical fingerprints; **2 object SHA-256 checksums** and complete inventory matched.
- Active policy/consumption survived intact; matching key decrypts synthetic secrets, wrong key is rejected.
- Runtime queries return only each tenant's own records. Tampered document downloads fail; restored bytes/final fingerprints match.
- Final run: **4.465 s** backup, **16.387 s** restore/verification, **72.933 s** total including setup. Tiny dataset proves no production capacity/RTO.
- Runner removed its containers, volumes, network, temporary backup/key material. Earlier parsing/volume-ownership harness issues were corrected only in disposable resources. Repeat runs also passed before the final active-ledger fixture.

**Limits:** the independent bundle copy remains on the same host. No real off-host service/R2 restore, production-size dataset, PITR, durable key escrow, provider outage or approved RPO/RTO was tested. M6-01 remains open for these production gates.

## Remaining readiness gates

Provider-wide sharing/reset/billing/owner reconciliation and explicitly accepted budget deployment/activation; upstream dependency advisories and final remote CI; selected hosting/regions/capacity; actual off-host/R2/key recovery and RPO/RTO; external dead-man/uptime and alert ownership; memory/slow-query/provider-spend signals; privacy/retention/export/deletion; historical failed-job payload/custom-log review; operator and deployment/rollback drills; final security/full browser/WebKit/manual accessibility/pilot UAT; explicit launch approval.

The [release and recovery runbook](release-runbook.md) is prepared, not executed. [M5 status](../m5/status.md), [M6 status](status.md) and the [remaining-task register](../shared/remaining-tasks.md) preserve these gates. Neither milestone is declared complete.

## Handoff cleanup

Stopped the owned loopback browser container and removed only the four named session-created readiness databases. Restore runner cleanup completed; existing services/data were preserved. Removed temporary inspected screenshots, raw received-message/header material and the stopped browser fixture’s environment file. Synthetic staging delivery history/accounts remain; optional preferences and schedules are disabled.
