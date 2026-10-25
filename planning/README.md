# WeavDash Planning

Current resume point: [implementation handoff](implementation-handoff.md). All unfinished, in-progress and deferred work is retained in the [remaining-task register](remaining-tasks.md). M5 is partially implemented; [M6 preparation](m6-status.md) has started with its dependencies and release gates still open.

**Confirmed D27 — current priority (2026-10-25):** platform-owner/super-admin administration is a required launch feature and the next implementation package before advancing milestones. Start **ADM-01** (authorization/MFA/bootstrap), then complete ADM-02–ADM-07; see the [administration contract](platform-administration-contract.md). The self-contained [Claude Design handoff](platform-administration-design-handoff.md) specifies screens, entities, UI columns, actions and states for the future visual reference. Preserve the verified CRM work. UI-07, broader UI-05 acceptance and remaining M5 work follow; no test data has been removed.

**CRM repair (2026-10-25):** UI-06 is implemented and locally verified: immediate server reminder segments, filter/cursor/history consistency, shared component reuse, accurate page counts/privacy copy and accessible stale-edit recovery. See [CRM evidence and desktop/mobile screenshots](evidence/crm-repair-2026-10-25/README.md). UI-07 notification feedback follows the D27 administration package, followed by broader UI-05 reference/deviation acceptance, then M5-02. M5/M6 and inherited release gates remain open.

**Pipeline repair (2026-10-25):** UI-02 filtering/pagination/count/stage repairs and UI-05 mobile capture/save/fixture engineering repairs are implemented with local verification. The real production board now appears in the visual fixture. See [repair evidence](evidence/pipeline-repair-2026-10-25/README.md). UI-06 was the next task at this checkpoint and now has the CRM evidence above; UI-07 follows the D27 administration package. Broader UI-05 reference acceptance and inherited release gates remain open; M5-02 follows the remaining UI work.

**Historical UI review (2026-10-25):** shared keyboard/accessibility repairs pass their scoped checks. The complete update also adds a CRM redesign and global toast/controller feedback; [review evidence](evidence/ui-plan-review-2026-10-25/README.md) adds **UI-06/UI-07** for CRM filter behavior/component reuse and truthful queued-email feedback. **UI-02 is next**, followed by UI-05–UI-07 acceptance work, then M5-02. The UI revision remains pending acceptance.

**UI refinement (2026-10-25):** the user-approved frontend-only refinement improves shared actions, dialogs, keyboard accessibility, section spacing and responsive forms. See the [audit and verification evidence](evidence/ui-refinement-2026-10-25/README.md). Pipeline filtering/count/stage repairs and full production-board fixture acceptance remain open; the earlier review below is retained as historical evidence.

**Historical UI review (2026-10-24):** the uncommitted design revision needs pipeline and accessibility corrections before acceptance. [Review and evidence](evidence/ui-review-2026-10-24/README.md) feed **UI-02–UI-05** in the register; historical milestone passes do not certify this revision.

Status: milestone-by-milestone implementation is authorized by the user. Development may proceed before pilot recruitment; pilot validation remains required before commercial launch. Detailed business and operating decisions remain under review. M1 automated gates passed; manual release reviews remain pending under D19/D20. M2 and M3 development is complete with automated gates passed; manual release acceptance remains pending. See [M1 status](m1-status.md) for evidence and [M2 status](m2-status.md) for CRM implementation, including [contacts/reminders](m2-crm-contract.md) and [manual templates](m2-outreach-template-contract.md). See [M3 status](m3-status.md) for onboarding/public presence and verification. See [M0 delivery backlog](m0-backlog.md) for readiness and unresolved gates.

**Confirmed D21–D23:** M3A implementation is now authorized and precedes M4. Connected brand/partner portals, explicit relationships and both reference dashboard compositions are implemented; automated gates have passed; manual release reviews remain pending. See [M3A status](m3a-status.md), [contract](m3a-portal-contract.md) and [portal UI alignment](portal-ui-alignment.md).

M4 development and automated verification are complete on `m4/financial-records-entitlements`: private draft invoices, manual receipt/allocation history and explicit time-limited pilot entitlements. See [M4 status](m4-status.md) and [finance/entitlement contract](m4-finance-contract.md). Pricing and numerical commercial quotas remain open; issuance, charging and transfers remain gated.

**Confirmed D25:** M5 includes required transactional email notifications independently of optional future Gmail/Outlook syncing. The durable outbox supports explicitly requested partner and onboarding invitation email, including onboarding replacements; see [M5 status](m5-status.md), [first-slice contract](m5-notification-contract.md), [onboarding invitation contract](m5-onboarding-email-contract.md) and [email requirements](m5-email-notifications.md). Onboarding lifecycle notifications and the rest of M5 remain pending; external provider delivery is unverified.

## Reading order

1. [Project overview](project-overview.md): business concept, evidence, and source-access limitations.
2. [Requirements and gap analysis](requirements.md): PDF requirements, mockup additions, and inconsistencies.
3. [Architecture](architecture.md): approved stack, service boundaries, and durable automation.
4. [Database design](database-design.md): tenancy, sharing, entities, and integrity rules.
5. [API design](api-design.md): proposed endpoints and security contracts.
6. [Authentication and subscriptions](auth-and-subscriptions.md): identity, permissions, and commercial packaging.
7. [MVP scope](mvp-scope.md): launch boundary and deferred capabilities.
8. [Implementation roadmap](implementation-roadmap.md): dependencies, acceptance gates, and testing.
9. [Deployment and operations](deployment-and-operations.md): development VPS and production transition.
10. [Decisions for approval](decisions.md): open business and architectural questions.
11. [M0 delivery backlog](m0-backlog.md): implementation sequence, acceptance checks, and readiness gates.

## Evidence labels

- **PDF**: business-plan requirement; page and section references appear throughout.
- **Mockup**: source-observed concept, UI, or behavior; not evidence of a working backend.
- **Confirmed**: explicit user decision in this conversation.
- **Proposal**: technical recommendation requiring review.
- **Assumption / Open**: unresolved input; must not silently become a requirement.

Confirmed launch scope: creator pipeline and brand onboarding first, with manual payment reconciliation initially. Confirmed stack: Laravel/PHP, Vue 3 + TypeScript + Inertia.js, Tailwind CSS + shadcn-vue, PostgreSQL with RLS, Redis/Laravel queues and scheduler, Docker Compose, and GitHub Actions. AWS S3 versus OVH Object Storage remains open. Development sizing starts with an estimated 2 vCPU / 4 GB RAM / approximately 80 GB SSD; provision after measurement and budget review. Other defaults remain proposals. The PDF is the primary business source. Its prices, market claims, targets, and regulatory statements are working assumptions, not independently verified facts.

See the [M3A implementation handoff](m3a-implementation-handoff.md) for approved D23 login designs, registration and relationship lifecycle, feature states, execution order and next-chat instructions.
