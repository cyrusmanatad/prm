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
| M1 — foundation | [Account, workspace, security and setup verification](m1/evidence.md) |
| M2 — creator CRM | [CRM verification](m2/evidence.md) |
| M3 — onboarding and public presence | [Onboarding and public-presence verification](m3/evidence.md) |
| M3A — connected portals | [Portal verification](m3a/evidence.md), [reference inventory](m3a/reference-evidence.md), [brand avatar](m3a/brand-avatar-evidence.md), [Partner navigation](m3a/partner-navigation-evidence.md) |
| M4 — finance and entitlements | [Finance verification](m4/evidence.md) |
| M5 — automation and notifications | [Milestone overview](m5/evidence.md), [onboarding email](m5/onboarding-email-evidence.md), [onboarding lifecycle](m5/onboarding-lifecycle-evidence.md), [schedules](m5/schedules-evidence.md), [scheduled delivery](m5/scheduled-delivery-evidence.md), [follow-up drafts](m5/follow-up-drafts-evidence.md), [logged replies](m5/logged-reply-evidence.md), [four-space formatting](m5/four-space-formatting-evidence.md) |

## Workstream and shared reports

These now sit with their owning milestone/workstream topics. Their inclusion here does not claim completion of a milestone or release gate.

| Area | Evidence |
| --- | --- |
| Professional | [Hub and subscriptions](professional/hub-evidence.md), [UI and pipeline panels](professional/ui-evidence.md) |
| Partnerships | [Brand join links](m3a/brand-join-links-evidence.md) |
| Pipeline | [Filtering and responsive repair](m2/pipeline-repair-2026-10-25-evidence.md), [drag interactions](m2/pipeline-drag-evidence.md) |
| CRM | [Contacts and reminders repair](m2/crm-repair-2026-10-25-evidence.md) |
| Shared interactions | [Confirmation dialogs](shared/confirmation-modals-evidence.md), [notification feedback](shared/notification-feedback-2026-10-25-evidence.md) |
| Visual reviews | [October 24 review](shared/ui-review-2026-10-24-evidence.md), [October 25 plan review](shared/ui-plan-review-2026-10-25-evidence.md), [UI refinement](shared/ui-refinement-2026-10-25-evidence.md), [portal reference review](shared/portal-reference-review-2026-10-26-evidence.md) |
| Branding | [Favicon](shared/favicon-evidence.md) |

- [Staging deployment and verification](shared/staging-deployment.md) — registration, persistent local test uploads, email, data-preserving staging rename, resource trial and recovery.
