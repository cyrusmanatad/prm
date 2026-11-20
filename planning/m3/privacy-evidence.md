# Privacy export implementation and verification

Date: 2026-11-20. Task: M3-02 / PRIV-02. Revision: uncommitted working tree based on `main` at `3ec3847`, including the earlier PRIV-01 audit and D40 policy approval. Local disposable PostgreSQL/Redis/app environments only. **No staging/production deployment, external message or deletion of existing business data.** The untracked staging rotation plan remains separate and untouched.

## Delivered behavior

- `/account/exports` creates personal-account or current-owner workspace ZIPs containing explicit JSON/CSV projections, counts/hash manifest and eligible original documents. Linked from the shared account menu and account security. Account includes its own private automation; workspace excludes members' private drafts/security data and has no platform/support override.
- Current password and fresh enrolled TOTP; workspace export always requires MFA enrollment. Fifteen-minute session proof is invalidated by changed identity/security basis. Downloads independently recheck requester, current access, expiry, dataset fingerprint, file size and hash inside a restricted-role repeatable-read transaction.
- Forced-RLS `privacy_exports` and runtime-append-only `privacy_export_events`; immutable receipt identity/scope/lifetime. SQL denies future-dated creation and premature runtime cleanup completion. Sharing locks follow partnership-before-registration order and serialize withdrawal against materialization.
- Private 0700 directory/0600 ZIP, UUID paths, no raw keys/secrets in archives. Scanned-clean document sizes/checksums verified; unsafe and staging-only unscanned documents listed as excluded. Oversize requests fail explicitly rather than truncate: 10,000 rows / 8 MiB projected rows / 48 MiB ZIP payload plus bounded overhead.
- Committed receipt precedes any file creation. `privacy:purge-exports`, scheduled every five minutes, retries expired/failed/abandoned partial/final files and marks cleanup complete only after absence checks. Active builds/downloads hold the same advisory lock. Failures emit aggregate warnings; download expiry does not depend on scheduler success.

Implementation and payload/operating limits: [contract](privacy-contract.md#delivered-priv-02-export-interface). Source: [controller](../../src/app/Http/Controllers/PrivacyExportController.php), [orchestration](../../src/app/Services/PrivacyExports.php), [explicit projections](../../src/app/Services/PrivacyExportData.php), [archive builder](../../src/app/Services/PrivacyExportArchive.php), [schema](../../src/database/sql/privacy-exports.sql) and [acceptance tests](../../src/tests/Acceptance/PrivacyExportTest.php).

## Commands and results

PHP ran in the existing local PHP **8.5.11** image with source mounted into isolated Compose project `weavdash-privacy`. Separate synthetic credentials, PostgreSQL databases `weavdash_privacy` / `weavdash_privacy_integration`, Redis and private storage; web at loopback `localhost:8012`. Existing project `weavdash` and its data/services were preserved. After verification, the owned test app/PostgreSQL/Redis containers, network, database volume, synthetic archives and temporary configuration/credentials were removed; the isolated app is no longer running. The temporary Compose/environment files were private under `/tmp/weavdash-privacy-20261120`; values were never printed. No image download or new dependency was required.

| Check | Result |
| --- | --- |
| Fresh migrations; `scripts/grant-runtime.php`; clean rollback/reapply of `2026_11_20_000001_create_privacy_exports` | Passed. Rollback after receipts exist was separately tested and correctly refused. Final lifetime-policy revision reapplied on the unused integration export tables before final acceptance. |
| `vendor/bin/phpunit -c phpunit.acceptance.xml --filter PrivacyExportTest` | **11 tests / 143 assertions passed** on final schema. Cross-account/tenant and non-owner/platform-owner denial, MFA, changed credentials/membership/scans, >100-row completeness, nested JSON allowlist, formula injection, safe/excluded files, archive tampering, expiry, interrupted/failing cleanup, immutable scope/future-time denial and explicit size refusal. |
| Actual concurrent PostgreSQL processes in both sharing lock orders | Passed within that suite. `pg_blocking_pids` proves real waits; materialization-first blocks withdrawal, withdrawal-first refuses the waiting old snapshot with SQLSTATE 40001. Later download of the withdrawn share returns conflict. |
| `vendor/bin/phpunit -c phpunit.pgsql.xml` | **35 tests / 649 assertions passed**; separate disposable database with `rls-fixtures.sql`. |
| `psql -v ON_ERROR_STOP=1 -f tests/Integration/rls.sql` | **RLS_CHECKS_PASSED**; actual restricted runtime role, same disposable database. |
| `vendor/bin/phpunit --testsuite Unit,Feature` | **53 tests / 589 assertions passed**. |
| `npm test` | **157 component tests passed**; shared account-menu expectations updated for the new entry. |
| `npm run format:check:changed`; `npm run typecheck`; `npm run build` | Passed; seven supported new/modified frontend files checked with pinned Prettier. |
| `vendor/bin/pint --test --dirty` | Passed for changed/new PHP. |
| `python3 src/scripts/check-secrets.py` | Positive control and **1,376-file** source scan passed; no unreviewed findings. |
| `npx playwright test privacy-exports --project=desktop --project=mobile --reporter=list` | **4 Chromium journeys passed** on the isolated app. Final run includes keyboard submission/focus, password error/clearing, account ZIP download, changed-data recovery and MFA workspace creation. |
| Documentation links and `git diff --check` | Passed: 726 local file-link targets across 16 added/modified Markdown files; current indexes, M3 status/evidence, contract, handoff/register, API/database/auth/operations, roadmap and root README updated. |

Browser command uses `PRIVACY_TEST_COMPOSE` pointing to the temporary isolated Compose file, `PLAYWRIGHT_BASE_URL=http://localhost:8012` and `PLAYWRIGHT_OUTPUT_DIR` under `/tmp`. Tests require synthetic migration fixtures, never an existing shared environment. `CAPTURE_BROWSER_IMAGES=1` is optional and writes only temporary images under that output directory. Default Playwright screenshot/trace capture stays off.

## UI inspection and corrections

Reused FoundationShell, ContentSection, FormField, SelectField, ActionGroup, StatusBadge and Button; extracted ExportHistory for the repeated receipt presentation. Inspected the account view at **1280 px**, workspace view at **1440×900** and **390×900**; existing burgundy/warm palette, headings, card borders/spacing, form controls and wrapped action layout remain aligned. Empty/error/ready states, expiry copy and fresh-export recovery are present. Responsive checks also passed **320 / 768 / 1024 px**, with no horizontal overflow. All **16 scoped Axe scans** in the desktop/mobile journeys had zero violations.

The initial browser run caught a download accessible-name/visible-label mismatch; fixed without weakening Axe. A new password-clearing assertion also verifies that successful Inertia form defaults do not retain password/code values; both current values and defaults clear. Earlier verification failures (test helper naming, ZIP file materialization timing and global cleanup counts in a reused disposable database) were corrected; final assertions pass.

Temporary captures were inspected and removed after review; no repository screenshot/image evidence was added. Linux WebKit/native Safari, manual screen-reader/zoom and broad pilot UAT were not run for this slice. Current-candidate remote CI, production topology/shared archive storage and actual deployment remain unverified. Local ZIP creation uses PharData and adds no extension/dependency.

## Remaining work and resume point

**PRIV-01 and PRIV-02 are locally complete. Next: PRIV-03 closure review, scoped access/work barriers, confirmation and cancellation.** PRIV-04–PRIV-06 retained-class decisions/erasure, document-orphan reconciliation and processor/restore handling remain open. Export-artifact cleanup does not execute account/workspace deletion or ADM-07 fixture cleanup. Privacy-handler workflow for external-person requests is not delivered by this self-service export slice.

The Philippines remains the tentative business registration country; intended markets are USA, Germany, Malta, UK and Philippines. Class-specific retained-record periods, processing locations, provider capabilities and actual recovery/backup policy verification remain open. Keep financial/audit protections and all inherited release gates. Staging secret rotation retains its approved but deferred scope.

## Initial source audit (before PRIV-02)

Date: 2026-11-20. Revision: `3ec3847` on `main`; documentation-only working-tree changes. Scope: PRIV-01 / M3-02 source audit and proposed [privacy contract](privacy-contract.md). No application code, schema, data, service or environment was changed. The untracked staging secret rotation plan was preserved.

### Initial data and copy inventory

This inventory groups delivered table families by privacy responsibility; it is not a live database inventory or a claim that all fields are exportable.

| Data / purpose | Delivered records and copies | Boundary and follow-up |
| --- | --- | --- |
| Identity and access | `users`, `organizations`, `memberships`, sessions/reset tokens, `account_recovery_codes`, `account_security_events`, platform grants/suspensions | Shared identity spans workspaces. Password/MFA secrets and token hashes stay out of exports. User/workspace FKs and last-owner protection complicate physical deletion. |
| Private CRM and outreach | `pipeline_entries`, `pipeline_activities`, `brand_contacts`, `pipeline_contacts`, `reminders`, `outreach_templates` | Contact details/notes can occur in several rows. Existing contact unlink and onboarding-history guards must be preserved. |
| Public presence / capture | `media_kits`, `lead_links`, `public_lead_receipts` | Unpublishing/revocation removes access but retains records. Receipts retain idempotency keys/payload hashes. Already downloaded/public copies cannot be recalled. |
| Onboarding | `onboarding_requests`, `onboarding_sessions`, `onboarding_documents`, `onboarding_submissions`, `onboarding_events`, `onboarding_return_links` | Draft fields and immutable JSON snapshots duplicate contact/brief data. Document filenames, checksums and keys are metadata; actual bytes live on the private onboarding disk. |
| Brand relationships and sharing | `partner_invitations`, `partnerships`, `partner_join_links`, `lead_registrations`, `partner_shared_profiles`, `workspace_sign_ins` | Recipient email, relationship names, shared contact fields, reusable encrypted codes and sign-in metadata require separate rules. Visibility withdrawal is distinct from erasure. |
| Brand approval | `brand_requests`, `brand_request_events`, `account_notification_outbox`, `account_notification_resolutions` | Application/contact fields, decision reasons and account notification routing persist independently of a created workspace. Include rejected/pending applicants. |
| Automation | `automation_preferences`, `automation_consent_events`, `business_schedules`, `schedule_runs`, `follow_up_drafts` | Actor-private drafts/recipient content and consent/version history. Redis work is reconstructed from PostgreSQL; deleting queued jobs alone is insufficient. |
| Delivery/accounting | `notification_outbox`, `notification_resolutions`, `notification_delivery_windows`, `mail_budget_policy`, `mail_budget_attempts` | Business outbox can hold encrypted URLs/recipients. Both outboxes must respect closure; uncertainty is not replayable and budget consumption is not refunded. Provider copies remain outside local erasure. |
| Finance/entitlements | Invoice drafts/lines/commands, manual payments/allocations/reversals/commands, pilot plan versions/assignments/admin events | Owner-only finance, immutable history and command hashes/references need explicit exception policy. Current cleanup blocks finance-bearing workspaces. |
| Governance and operations | `audit_logs`, `platform_audit`, `support_grants`, fixture batches/marks, `cleanup_runs`, observations, `monitor_checks` | Actor/subject references, free-text reasons, stored cleanup manifests and monitoring metadata need inventory/retention. No default operator-private export privilege. Audit is protected under D32. |
| Secondary copies | Redis jobs/cache, database jobs/batches/failed jobs, sessions, logs, temporary export files, storage transfer copies, encrypted database/document/key backups, R2 objects/possible versions and provider mail | Failed-job payloads may retain private data despite exception sanitization. Provider settings and actual deployed backup/version lifetimes were not inspected. Recovery must not resurrect erased data. |

Schema sources: [foundation](../../src/database/sql/foundation.sql), [CRM](../../src/database/sql/crm.sql), [onboarding](../../src/database/sql/onboarding.sql), [portals](../../src/database/sql/portals.sql), [sharing](../../src/database/sql/brand-lead-visibility.sql), [Brand approval](../../src/database/sql/brand-approval.sql), [automation](../../src/database/sql/automation-schedules.sql), [drafts](../../src/database/sql/follow-up-drafts.sql), [outbox](../../src/database/sql/notification-outbox.sql), [finance](../../src/database/sql/manual-payments.sql), [governance](../../src/database/sql/platform-governance.sql) and the migrations that install/extend them.

### Initial source behavior and gaps

| Finding | Source evidence | Consequence |
| --- | --- | --- |
| Five resource CSV exports already exist for Professional workspaces | [ProfessionalController](../../src/app/Http/Controllers/ProfessionalController.php), [routes](../../src/routes/web.php) | Leads/contacts/reminders/invoices/payments; allowlisted fields, formula protection, finance authorization and materialization in tenant context. No complete personal/workspace privacy export, request ledger, attachment archive or expiry lifecycle. |
| Scoped deletes are not complete privacy erasure | [Pipeline](../../src/app/Services/Pipeline.php), [CRM](../../src/app/Services/Crm.php), [templates](../../src/app/Services/OutreachTemplates.php) | Lead deletion refuses onboarding history; contacts must be unlinked. Existing audit/history and other copies need separate disposition. |
| Ordinary withdrawal and partnership termination retain shared lead fields | [Brand visibility SQL](../../src/database/sql/brand-lead-visibility.sql), `app_withdraw_lead_registration` / `app_end_partnership_registrations` | Withdrawal hides content from the Brand but does not erase the retained copy. By contrast, the source-lead DELETE trigger clears shared name/summary/contact fields and leaves a withdrawn tombstone. Do not describe source deletion as retaining those fields. |
| Unused-file removal has no durable deletion retry | [OnboardingDocuments](../../src/app/Services/OnboardingDocuments.php) | Metadata deletion commits before the `afterCommit` object removal. Failure/crash can leave an unreferenced object; earlier upload failures can also leave bytes after outer rollback. Submitted snapshot references prevent ordinary removal. |
| PostgreSQL remains the durable work authority | [BusinessSchedules](../../src/app/Services/BusinessSchedules.php), [NotificationOutbox](../../src/app/Services/NotificationOutbox.php), [AccountNotifications](../../src/app/Services/AccountNotifications.php), [scheduler](../../src/routes/console.php) | Existing consent/revocation checks and cancellation are useful foundations, but there is no dedicated privacy deletion barrier/lifecycle across both outboxes, uploads and reconstruction. |
| Erasure conflicts with retained references and runtime permissions | [foundation SQL](../../src/database/sql/foundation.sql), [onboarding SQL](../../src/database/sql/onboarding.sql), [manual payment SQL](../../src/database/sql/manual-payments.sql), [runtime grants](../../src/database/sql/runtime-grants.sql) | Several user/workspace references prevent removal; immutable snapshots and financial/audit policies cannot be bypassed by an unreviewed cascade. Needs scoped schema/functions and explicit retention exceptions. |
| Administration cleanup is an immutable preview only | [PlatformGovernanceController](../../src/app/Http/Controllers/PlatformGovernanceController.php), [governance SQL](../../src/database/sql/platform-governance.sql) | Exact batch manifests and protected/blocked categories exist; no execution path. They do not provide a general privacy workflow or a complete current file/object inventory. |
| Local and R2 private onboarding disks exist | [filesystems](../../src/config/filesystems.php), [storage transfer](../../src/app/Services/OnboardingStorageTransfer.php), [staging R2 evidence](../shared/staging-r2-evidence.md) | Source confirms supported disks and prior staging evidence; this audit did not verify live object listings, versioning, lifecycle, regions or processor deletion. |

### Initial audit checks and limits

Read the planning index/current handoff/register, M3 status/contract, D11/D20/D32/D33, administration contract, M6 readiness evidence and relevant services/routes/schema/configuration. Used `rg --files`, targeted `rg -n`, and source reads to trace export/delete/storage/queue behavior. No external legal or provider-policy claims were researched or adopted; product timings were subsequently approved under D40; statutory retained-class periods remain unresolved.

Documentation validation: 599 local file-link targets across all 10 added/modified Markdown files and `git diff --check` passed. New privacy-document heading links and whitespace checks also passed. Planning/evidence indexes, M3 status, shared handoff/register, decision register, roadmap and database design now link to this work. Application tests, migrations, browser checks and live provider operations were not run: this slice changes documentation only. No UI changed; no screenshot evidence was created.
