**Staging R2 deployed (2026-11-09):** private onboarding attachments now use Cloudflare R2 bucket `weavdash`; local development remains local. Existing attachment copied and checksum-verified after backup; live upload/removal, authorization, scan-state guards, desktop/mobile journeys and container-replacement persistence passed. See [migration/recovery](shared/staging-r2-storage.md) and [verification / remaining gates](shared/staging-r2-evidence.md). Existing staging fixes are included; dependency advisories and inherited release gates remain open.

**Staging (2026-11-09):** registration, persistent local test uploads, email, verification and recovery are tracked in [staging deployment](shared/staging-deployment.md). Both P2 findings in the [saved full review](shared/staging-review.md) are resolved and locally verified; the fixes are included in the verified R2 staging deployment. This does not advance commercial release gates.

**Professional UI refinement (2026-11-08):** the hub and eight tools now share Professional navigation and presentation. See the [implementation and verification handoff](professional/ui-handoff.md). M5-07 and existing administration/release gates remain unchanged.

**Current checkpoint (2026-11-07):** [Professional Hub and manual subscriptions](professional/hub-status.md) are implemented with local verification. Necessary ADM-01/02/04 dependencies are included; remaining administration and release gates stay open. Next backend slice: M5-07.

# WeavDash Planning

## Document organization

**Confirmed (2026-11-08):** each milestone owns a folder containing its contracts, status, handoffs and evidence Markdown: `planning/<milestone>/<topic>.md`. For example, M2 pipeline planning is `planning/m2/pipeline-contract.md`; its verification reports sit in the same `m2/` folder. Follow this structure for future milestones, without repeating the milestone prefix in filenames. Shared topics and separate workstreams have their own folders below.

| Folder | Starting points |
| --- | --- |
| `m0/` | [Delivery backlog](m0/backlog.md) |
| `m1/` | [Status](m1/status.md), [manual acceptance](m1/manual-acceptance.md), [evidence](m1/evidence.md) |
| `m2/` | [Status](m2/status.md), [pipeline](m2/pipeline-contract.md), [contacts/reminders](m2/crm-contract.md), [templates](m2/outreach-template-contract.md), [evidence](m2/evidence.md) |
| `m3/` | [Status](m3/status.md), [onboarding/public presence](m3/onboarding-contract.md), [evidence](m3/evidence.md) |
| `m3a/` | [Status](m3a/status.md), [portal contract](m3a/portal-contract.md), [handoff](m3a/implementation-handoff.md), [UI alignment](m3a/ui-alignment.md), [evidence](m3a/evidence.md) |
| `m4/` | [Status](m4/status.md), [finance contract](m4/finance-contract.md), [evidence](m4/evidence.md) |
| `m5/` | [Status](m5/status.md), [notification contract](m5/notification-contract.md), [evidence](m5/evidence.md) |
| `m6/` | [Status](m6/status.md), [readiness contract](m6/readiness-contract.md) |
| `shared/` | [Current handoff](shared/implementation-handoff.md), [remaining tasks](shared/remaining-tasks.md), [roadmap](shared/implementation-roadmap.md), [decisions](shared/decisions.md); project-wide architecture and requirements follow the reading order below |
| `administration/` | [Contract](administration/contract.md), [design handoff](administration/design-handoff.md) |
| `professional/` | [Status](professional/hub-status.md), [contract](professional/hub-contract.md), [UI handoff](professional/ui-handoff.md) |

The [evidence index](evidence.md) lists verification Markdown in these folders. Existing non-Markdown artifacts remain under `planning/evidence/`; moved reports link to them at their original locations. New evidence retains concise text records and no screenshots by default. This organization changes document locations only; milestone scope, decisions and acceptance gates remain unchanged. Older flat-path references in external skills should be resolved through this index.

**M5-06 checkpoint (2026-10-31, D28):** explicit logged replies now atomically clear the lead next-action date and cancel existing private follow-up drafts, approvals, schedules and ready runs across workspace members without disclosing their content. See the [reply/cancellation contract](m5/logged-reply-contract.md) and [local verification/screenshots](m5/logged-reply-evidence.md), based on `fb67cfb`. Unrelated reminders remain active; fresh explicit follow-ups remain possible. **Next backend task: M5-07 approval-basis invalidation/reconciliation.** Claude retains small-screen acceptance; administration, UI-05, M5-08 delivery/operator/budget, provider and inherited release gates remain open. M5/M6 are incomplete; the existing source-scan evidence-checksum false positive remains documented. Older checkpoints retain their tested scope.

**M5-05 checkpoint (2026-10-31, D28):** private follow-up drafts, durable scheduled preparation and the approval inbox are implemented with local backend/desktop evidence, based on `855167c`. See the [recipient/content/manual-handoff contract](m5/follow-up-drafts-contract.md) and [verification/screenshots](m5/follow-up-drafts-evidence.md). The non-AI path supports writing, editing, version-specific approval, guarded plain-text download and confirmed discard. Scheduled drafts contain no recipient or private CRM interpolation. Approval never sends email; application-sent approved outreach/review notices remain explicit M5-08 work after M5-06/M5-07 safety gates. **Next backend task: M5-06 logged-reply cancellation.** Claude retains small-screen acceptance; administration, UI-05, provider and inherited release gates remain open. Source scanning retains the documented pre-existing evidence-hash false positive. M5/M6 are incomplete. Older checkpoints retain their original scope.

**M5-04 checkpoint (2026-10-31, D28):** reminder and weekly digest/review email delivery is implemented with backend/desktop local verification, based on `f0f6066`. See the [delivery contract](m5/scheduled-delivery-contract.md) and [evidence/screenshots](m5/scheduled-delivery-evidence.md). Durable ready runs produce outbox intent atomically; consent/access/entitlement/versions are rechecked at send reservation. Notices go only to the schedule owner's current verified account. Weekly reviews include tenant-scoped due-reminder/onboarding-review counts and suppress older pending occurrences after downtime. `/automation` adds reminder controls and truthful delivery history. **Next backend task: M5-05 follow-up drafts and approval inbox.** Small-screen acceptance remains with Claude; provider receipt is M5-09. Source scanning reports one verified pre-existing evidence-hash false positive; scanner rules remain unchanged. UI-05, administration and inherited release gates stay open; M5/M6 remain incomplete. Older checkpoints below retain their original scope.

**Current backend checkpoint (2026-10-26, D28):** M5-03 persistent schedules, timezones, preferences and consent are implemented with local backend/desktop verification; see the [contract](m5/schedules-contract.md) and [evidence](m5/schedules-evidence.md). Named versioned rules, explicit opt-in, current authorization/entitlement checks and PostgreSQL/Redis recovery are in place. Scheduled email delivery remains M5-04. **Next backend slice: M5-04 reminders and digest/review notifications.** Codex continues backend/necessary desktop work; Claude owns administration and small-screen testing/refinement. UI-05, mobile/provider and inherited release gates remain open; M5 is incomplete. M5-02 lifecycle email retains its [contract](m5/onboarding-lifecycle-contract.md) and [evidence](m5/onboarding-lifecycle-evidence.md). Earlier priority snapshots below are historical.

**Confirmed session scope (2026-10-25):** the user held administrator implementation for this session and then authorized continuing development. UI-07 notification feedback was completed under that session scope while ADM-01–ADM-07 remain unimplemented and required; this does not waive D27's launch priority or advance M5/M6 acceptance.

Current resume point: [implementation handoff](shared/implementation-handoff.md). All unfinished, in-progress and deferred work is retained in the [remaining-task register](shared/remaining-tasks.md). M5 is partially implemented; [M6 preparation](m6/status.md) has started with its dependencies and release gates still open.

**UI-07 (2026-10-25):** queued-email copy, repeated/initial/history feedback, modal notification accessibility, reduced-motion dismissal and persistent clipboard recovery are implemented and locally verified. Formatting/types/build, 51 component tests, PHP 29/256 and all 22 distinct affected desktop/mobile journeys have passing evidence across the broad run and two-test accessibility follow-up. See [notification feedback evidence](shared/notification-feedback-2026-10-25-evidence.md). Administration remains held for this session only; ADM-01–ADM-07 are still required and unimplemented. Broader UI-05 reference/deviation acceptance and M5-02 remain open.

**Confirmed D27 — current priority (2026-10-25):** platform-owner/super-admin administration is a required launch feature and the next implementation package before advancing milestones. Start **ADM-01** (authorization/MFA/bootstrap), then complete ADM-02–ADM-07; see the [administration contract](administration/contract.md). The self-contained [Claude Design handoff](administration/design-handoff.md) specifies screens, entities, UI columns, actions and states for the future visual reference. Preserve the verified CRM work. Broader UI-05 acceptance and remaining M5 work follow; no test data has been removed.

**CRM repair (2026-10-25):** UI-06 is implemented and locally verified: immediate server reminder segments, filter/cursor/history consistency, shared component reuse, accurate page counts/privacy copy and accessible stale-edit recovery. See [CRM evidence and desktop/mobile screenshots](m2/crm-repair-2026-10-25-evidence.md). UI-07 is also locally verified. Broader UI-05 reference/deviation acceptance and M5-02 follow the retained D27 administration priority. M5/M6 and inherited release gates remain open.

**Pipeline repair (2026-10-25):** UI-02 filtering/pagination/count/stage repairs and UI-05 mobile capture/save/fixture engineering repairs are implemented with local verification. The real production board now appears in the visual fixture. See [repair evidence](m2/pipeline-repair-2026-10-25-evidence.md). UI-06 was the next task at this checkpoint and now has the CRM evidence above; UI-07 is also locally verified under the session-specific hold. Broader UI-05 reference acceptance and inherited release gates remain open; M5-02 follows the remaining UI work.

**Historical UI review (2026-10-25):** shared keyboard/accessibility repairs pass their scoped checks. The complete update also adds a CRM redesign and global toast/controller feedback; [review evidence](shared/ui-plan-review-2026-10-25-evidence.md) adds **UI-06/UI-07** for CRM filter behavior/component reuse and truthful queued-email feedback. **UI-02 is next**, followed by UI-05–UI-07 acceptance work, then M5-02. The UI revision remains pending acceptance.

**UI refinement (2026-10-25):** the user-approved frontend-only refinement improves shared actions, dialogs, keyboard accessibility, section spacing and responsive forms. See the [audit and verification evidence](shared/ui-refinement-2026-10-25-evidence.md). Pipeline filtering/count/stage repairs and full production-board fixture acceptance remain open; the earlier review below is retained as historical evidence.

**Historical UI review (2026-10-24):** the uncommitted design revision needs pipeline and accessibility corrections before acceptance. [Review and evidence](shared/ui-review-2026-10-24-evidence.md) feed **UI-02–UI-05** in the register; historical milestone passes do not certify this revision.

Status: milestone-by-milestone implementation is authorized by the user. Development may proceed before pilot recruitment; pilot validation remains required before commercial launch. Detailed business and operating decisions remain under review. M1 automated gates passed; manual release reviews remain pending under D19/D20. M2 and M3 development is complete with automated gates passed; manual release acceptance remains pending. See [M1 status](m1/status.md) for evidence and [M2 status](m2/status.md) for CRM implementation, including [contacts/reminders](m2/crm-contract.md) and [manual templates](m2/outreach-template-contract.md). See [M3 status](m3/status.md) for onboarding/public presence and verification. See [M0 delivery backlog](m0/backlog.md) for readiness and unresolved gates.

**Confirmed D21–D23:** M3A implementation is now authorized and precedes M4. Connected brand/partner portals, explicit relationships and both reference dashboard compositions are implemented; automated gates have passed; manual release reviews remain pending. See [M3A status](m3a/status.md), [contract](m3a/portal-contract.md) and [portal UI alignment](m3a/ui-alignment.md).

M4 development and automated verification are complete on `m4/financial-records-entitlements`: private draft invoices, manual receipt/allocation history and explicit time-limited pilot entitlements. See [M4 status](m4/status.md) and [finance/entitlement contract](m4/finance-contract.md). Pricing and numerical commercial quotas remain open; issuance, charging and transfers remain gated.

**Confirmed D25:** M5 includes required transactional email notifications independently of optional future Gmail/Outlook syncing. The durable outbox supports explicitly requested partner and onboarding invitation email, including onboarding replacements; see [M5 status](m5/status.md), [first-slice contract](m5/notification-contract.md), [onboarding invitation contract](m5/onboarding-email-contract.md) and [email requirements](m5/email-notifications.md). Onboarding lifecycle notifications and the rest of M5 remain pending; external provider delivery is unverified.

## Reading order

1. [Project overview](shared/project-overview.md): business concept, evidence, and source-access limitations.
2. [Requirements and gap analysis](shared/requirements.md): PDF requirements, mockup additions, and inconsistencies.
3. [Architecture](shared/architecture.md): approved stack, service boundaries, and durable automation.
4. [Database design](shared/database-design.md): tenancy, sharing, entities, and integrity rules.
5. [API design](shared/api-design.md): proposed endpoints and security contracts.
6. [Authentication and subscriptions](shared/auth-and-subscriptions.md): identity, permissions, and commercial packaging.
7. [MVP scope](shared/mvp-scope.md): launch boundary and deferred capabilities.
8. [Implementation roadmap](shared/implementation-roadmap.md): dependencies, acceptance gates, and testing.
9. [Deployment and operations](shared/deployment-and-operations.md): development VPS and production transition.
10. [Decisions for approval](shared/decisions.md): open business and architectural questions.
11. [M0 delivery backlog](m0/backlog.md): implementation sequence, acceptance checks, and readiness gates.

## Evidence labels

- **PDF**: business-plan requirement; page and section references appear throughout.
- **Mockup**: source-observed concept, UI, or behavior; not evidence of a working backend.
- **Confirmed**: explicit user decision in this conversation.
- **Proposal**: technical recommendation requiring review.
- **Assumption / Open**: unresolved input; must not silently become a requirement.

Confirmed launch scope: creator pipeline and brand onboarding first, with manual payment reconciliation initially. Confirmed stack: Laravel/PHP, Vue 3 + TypeScript + Inertia.js, Tailwind CSS + shadcn-vue, PostgreSQL with RLS, Redis/Laravel queues and scheduler, Docker Compose, and GitHub Actions. AWS S3 versus OVH Object Storage remains open. Development sizing starts with an estimated 2 vCPU / 4 GB RAM / approximately 80 GB SSD; provision after measurement and budget review. Other defaults remain proposals. The PDF is the primary business source. Its prices, market claims, targets, and regulatory statements are working assumptions, not independently verified facts.

See the [M3A implementation handoff](m3a/implementation-handoff.md) for approved D23 login designs, registration and relationship lifecycle, feature states, execution order and next-chat instructions.
