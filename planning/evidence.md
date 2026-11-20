**M3-02 PRIV-03 (2026-11-20):** [Closure verification and handoff](m3/closure-evidence.md), following export commit `64b1a6f`.

**M3-02 export verification (2026-11-20):** [implementation, PostgreSQL/role/concurrency checks, archive/cleanup tests and desktop/mobile findings](m3/privacy-evidence.md), supporting the approved [privacy contract](m3/privacy-contract.md). Local disposable environments only; PRIV-03–PRIV-06 and release/deployment gates remain open.

**Email design (2026-11-18):** [shared HTML presentation, rules, commands, rendering findings and unverified client gates](shared/email-design.md). Local disposable stack only; not deployed.

**M5/M6 continuation (2026-11-18):** [seeder/CI repairs, budget enforcement, framework log redaction and synthetic database/document/key restore](m6/readiness-evidence.md); [budget targets and rollout gates](m5/sending-budget-proposal.md); [provider receipt/link trial](m5/provider-delivery-evidence.md). Local fixes are not deployed; no production acceptance.

**Brand lead visibility (2026-11-18):** [commands, results, rendering findings, unverified gates and staging runbook](shared/brand-lead-visibility-evidence.md) for the [contract](shared/brand-lead-visibility-contract.md). Local disposable stacks only; not deployed. The earlier [handoff](shared/brand-lead-visibility-handoff.md) was a documentation-only review.

**Staging presentation dataset (2026-11-18):** [provisioning, PostgreSQL isolation, backups and desktop/mobile verification](shared/staging-presentation-evidence.md).

**M5 small-screen acceptance (2026-11-17):** the phone-width checks carried to Claude for M5-02 to M5-06 passed locally in Chromium at 390 and 320 px after small touch-target and readability fixes; see the [small-screen evidence](m5/small-screen-evidence.md). Provider delivery (M5-09) and inherited manual reviews remain open.

**Brand workspace approval (2026-11-18):** [implementation, commands, rendering findings, rollout dry run and unverified gates](administration/brand-approval-evidence.md). Local disposable stack only; not deployed.

**Administration enrollment QR code (2026-11-17):** [B7 implementation and verification](administration/enrollment-qr-evidence.md). Local disposable stack only.

**Administration follow-up (2026-11-17):** [paused label, skipped work, date filters, overview items, journey investigation and gates](administration/follow-up-evidence.md). Local disposable stacks only.

**Administration console (2026-11-16):** [implementation, commands, rendering findings and unverified gates](administration/console-evidence.md). Local disposable stack only.

**M5-09 preparation (2026-11-14):** [matrix, proposed limits and runbook](m5/provider-delivery-acceptance.md); [preflight evidence and pending gates](m5/provider-delivery-evidence.md). The controlled-recipient trial is authorized and staging has been updated to `c13e960`; receipt/link trials are in progress. Production budgets stay open.

**PR #16 merged (2026-11-14):** recovery implementation and CI repair are complete on `main` at `c13e960`. See [repair scope and verification](m5/ci-repair-evidence.md). Next: M5-09 provider delivery/link-opening acceptance and actual budget approval; M5/M6 advancement remains gated.

**M5-10 recovery (2026-11-12):** [synthetic recovery results and environment](m5/recovery-evidence.md), with the [recovery contract](m5/recovery-contract.md). Local synthetic gates passed; provider/production/release acceptance remains separate.

**Staging R2 deployed (2026-11-09):** private onboarding attachments now use Cloudflare R2 bucket `weavdash`; local development remains local. Existing attachment copied and checksum-verified after backup; live upload/removal, authorization, scan-state guards, desktop/mobile journeys and container-replacement persistence passed. See [migration/recovery](shared/staging-r2-storage.md) and [verification / remaining gates](shared/staging-r2-evidence.md). Existing staging fixes are included; dependency advisories and inherited release gates remain open.

# Verification evidence

**Staging review resolved locally (2026-11-09):** the [full review comments, resolutions and validation](shared/staging-review.md) record both P2 fixes, feature/PostgreSQL/browser/proxy checks and passing formatting/types/build/Pint. No deployment occurred; remote validation remains pending a separately authorized rollout.

**Confirmed (2026-11-08):** organize new evidence by milestone and retain concise Markdown records. Screenshots are temporary inspection files, removed after review unless retention is explicitly requested. Existing reports and reference assets remain historical evidence; this convention does not change their original scope or acceptance status.

## Report locations

Verification Markdown lives beside its milestone's implementation topics:

- Milestone summary: `planning/m1/evidence.md`.
- Topic report: `planning/m5/schedules-evidence.md`, beside `schedules-contract.md`.
- Cross-milestone report: `planning/shared/<topic>-evidence.md`.
- Separately approved workstream: `planning/professional/<topic>-evidence.md` or its corresponding workstream folder.

Follow the [planning index](README.md#document-organization) for new milestones. Use descriptive lowercase hyphenated topic names without repeating the milestone prefix. Add reports to this index and link them from the relevant status/handoff. Record date, tested revision/working-tree scope, commands, results, visual inspection findings and viewport sizes where relevant, and remaining gates. Reference lengthy text logs when needed; avoid duplicating them across reports.

**Organization update (2026-11-08):** all existing planning and evidence Markdown topics have moved to their owning milestone/workstream folders, and repository links have been updated. Non-Markdown historical artifacts remain in `planning/evidence/`; the reports' relative links point to those original files. This supersedes the earlier evidence-only folder convention. No evidence images or logs were deleted, and no milestone acceptance changed. Documentation verification passed: 73 relocations, 1,188 local Markdown links with no broken links or stale repository paths, all 74 planning documents reachable from the planning index, and `git diff --check`. Application tests were not rerun for this documentation-only reorganization.

## Milestone reports

| Milestone | Evidence |
| --- | --- |
| M1 — foundation | [Account, workspace, security and setup verification](m1/evidence.md); [workspace-owner MFA](m1/owner-mfa-evidence.md) |
| M2 — creator CRM | [CRM verification](m2/evidence.md) |
| M3 — onboarding and public presence | [Onboarding and public-presence verification](m3/evidence.md) |
| M3A — connected portals | [Portal verification](m3a/evidence.md), [reference inventory](m3a/reference-evidence.md), [brand avatar](m3a/brand-avatar-evidence.md), [Partner navigation](m3a/partner-navigation-evidence.md) |
| M4 — finance and entitlements | [Finance verification](m4/evidence.md) |
| M5 — automation and notifications | [Milestone overview](m5/evidence.md), [onboarding email](m5/onboarding-email-evidence.md), [onboarding lifecycle](m5/onboarding-lifecycle-evidence.md), [schedules](m5/schedules-evidence.md), [scheduled delivery](m5/scheduled-delivery-evidence.md), [follow-up drafts](m5/follow-up-drafts-evidence.md), [logged replies](m5/logged-reply-evidence.md), [approval invalidation](m5/approval-basis-evidence.md), [delivery operations](m5/delivery-operations-evidence.md), [four-space formatting](m5/four-space-formatting-evidence.md) |

## Workstream and shared reports

These now sit with their owning milestone/workstream topics. Their inclusion here does not claim completion of a milestone or release gate.

| Area | Evidence |
| --- | --- |
| Professional | [Hub and subscriptions](professional/hub-evidence.md), [UI and pipeline panels](professional/ui-evidence.md) |
| Partnerships | [Brand join links](m3a/brand-join-links-evidence.md) |
| Partnerships | [Brand hub invitations](m3a/invitations-evidence.md) |
| Partnerships | [Table controls and partner avatars](m3a/table-controls-evidence.md) |
| Partnerships | [Compact partner pipeline](m3a/pipeline-compact-evidence.md) |
| Pipeline | [Filtering and responsive repair](m2/pipeline-repair-2026-10-25-evidence.md), [drag interactions](m2/pipeline-drag-evidence.md) |
| CRM | [Contacts and reminders repair](m2/crm-repair-2026-10-25-evidence.md) |
| Shared interactions | [Confirmation dialogs](shared/confirmation-modals-evidence.md), [notification feedback](shared/notification-feedback-2026-10-25-evidence.md) |
| Visual reviews | [October 24 review](shared/ui-review-2026-10-24-evidence.md), [October 25 plan review](shared/ui-plan-review-2026-10-25-evidence.md), [UI refinement](shared/ui-refinement-2026-10-25-evidence.md), [portal reference review](shared/portal-reference-review-2026-10-26-evidence.md) |
| Branding | [Favicon](shared/favicon-evidence.md) |
| Monitoring | [M6-03 first slice](m6/monitoring-evidence.md) |

- [Staging deployment and verification](shared/staging-deployment.md) — registration, persistent local test uploads, email, data-preserving staging rename, resource trial and recovery.
