# Decisions for Approval

## Recorded decisions

**D01 — Confirmed:** creator pipeline and brand onboarding are the first release; manual payment reconciliation initially. Automated transfers and attribution are not launch prerequisites.

**D02 — Confirmed:** Laravel/PHP backend with Vue 3, TypeScript, Inertia.js, Tailwind CSS and shadcn-vue; PostgreSQL with tenant RLS; Redis with Laravel queues and scheduler; Docker Compose and GitHub Actions. Vite builds frontend assets. Previous NestJS/React/BullMQ proposals are superseded. This is stack approval and an instruction to update planning documents, not permission to implement code.

**D04 — Direction recorded:** size the development OVH VPS to project needs. Initial planning recommendation is 2 vCPU / 4 GB RAM / approximately 80 GB SSD; consider 8 GB for shared/heavier development. Private uploads use a Laravel filesystem disk; AWS S3 or OVH Object Storage remains to be selected. No capacity guarantee or provider purchase is approved.

## Major open questions

| ID | Decision needed | Recommendation / consequence |
|---|---|---|
| D03 | Future sharing beyond the initial invitation scope? | D15 confirms free invited participation and explicit resource-scoped sharing. Creator contacts/notes/rates remain private; directory publication is opt-in. |
| D04 | Final development VPS plan, storage provider/region, and production budget/region? | Start from the recorded sizing estimate; compare AWS S3 and OVH storage cost, region, and required features. Confirm OS/disk/headroom before purchase; choose production hosting after pilot measurement. |
| D05 | Repeat/lost deal lifecycle and future automatic triggers? | D20 confirms explicit Start onboarding with no automatic stage trigger. Further deal lifecycle/automatic transition rules remain open. |
| D06 | What is paid at launch, and have 20–30 committed pilots been recruited? | Start with useful free/pilot entitlements; validate creator pricing before self-serve billing. Define “10 active brands,” grace/downgrade, and trial policy. |
| D07 | Must MVP issue real tax invoices/e-sign contracts, or track records/drafts? | Draft/manual records first; accountant-reviewed invoice issuance and integrated signatures later. This narrows PDF Phase 1 and needs explicit approval. |
| D08 | Is AI pitch drafting a release requirement, and what provider/budget is acceptable? | Optional consent-based pilot behind a flag; template/manual workflow remains usable. Mockup Claude choice is unapproved. |
| D09 | MFA enrollment/recovery, admin coverage, and later enterprise federation provider? | Password/session login is confirmed (D15); require MFA for platform operators and workspace owners before production (D18). Enforcement implementation remains pending. Select federation only when enterprise needs justify it. |
| D10 | Which actions may run without approval? | No automatic external pitches/contracts/financial actions initially; explicit user commands may send an invite. Start with named reminder templates, not rule builder. |
| D11 | Production retention/deletion, document purposes and jurisdiction? | D20 limits initial collection to business/contact/email/website/brief and excludes tax IDs, permits and shipping. Production retention/deletion and future sensitive collection remain open. |
| D12 | Later payout funds flow, provider permissions, fees, and attribution rules? | No custody in MVP. Before Phase 2/3 define merchant/payee ownership, payout approvals, unknown outcomes, return windows, link-vs-code precedence, and dispute process. No provider capability/approval is assumed. |

## Implementation authorization

**D13 — Confirmed:** the user authorized implementation one milestone at a time. This supersedes earlier planning-only restrictions, including the historical qualification in D02. Local scaffolding, dependency installation, application code, and verification are authorized within the approved stack. Hosting purchases, live integrations, and production deployment are not part of this instruction.

**D14 — Confirmed:** proceed with development before recruiting the planned 20–30 pilots; validate with pilots later. Pilot recruitment is not a prerequisite for M1. Commercial readiness still requires pilot evidence and business decisions.

**D15 — Confirmed initial defaults:** invited brands participate free through explicit resource-scoped sharing; creator notes/contacts/rates remain private. Financial features track drafts/manual records, with real tax issuance and integrated signatures deferred. AI is optional. Use Laravel password/session login. External actions require approval; an explicit user command may send an invitation. These settle the initial recommendations in D03, D07, D08, D09, and D10, but not provider budgets, production MFA policy, or commercial pricing.

**D16 — Confirmed:** keep the complete Laravel application and its dependencies, tests, build tools, and environment files in `src/`. Documentation/reference directories remain outside `src/`. Repository-level orchestration and GitHub workflow configuration remain at the root. This layout change does not expand M1 scope.

**D17 — Confirmed:** Docker targets stable PHP 8.5 using `php:8.5-cli-bookworm`. Builds pull current base images; the Redis extension is pinned to 6.3.0. The Composer minimum remains PHP 8.3 for local compatibility; this includes PHP 8.5. Container build/runtime verification remains required before M1 acceptance.

**D18 — Confirmed (2026-10-18):** require MFA for platform operators and workspace owners before production. This records the user-approved policy; MFA enrollment, challenge/recovery flows, and enforcement are not yet implemented or tested.

**D19 — Confirmed (2026-10-18):** the user authorized moving on to M2 while manual screen-reader, full browser zoom, and native Safari reviews remain pending. This permits continued development; it does not claim M1 manual acceptance, waive those release checks, or authorize production deployment. Carry the pending reviews across milestones.

**D20 — Confirmed (2026-10-18):** the user requested finishing M2 and then completing M3. M3 starts through an explicit “Start onboarding” action with no automatic stage trigger. Initial onboarding collects only business/contact name, business email, website and collaboration brief; tax IDs, permits and shipping details are excluded. This settles the initial D05 trigger and D11 collection boundary, not production retention/legal policy. Carried-forward manual release checks remain pending; development may proceed, but this request does not satisfy those checks or authorize production deployment or external messages.

Unanswered product recommendations remain proposals. Provider selection, sensitive-data collection, real invoice issuance, and external automation must be resolved before dependent features. See [M0 delivery backlog](../m0/backlog.md).

## Remaining inspection work

When localhost and browser tooling become available, inspect all seven boards at desktop/mobile widths; exercise state demonstrations; collect console/network errors; verify font/SVG rendering, contrast, focus order, keyboard navigation, and form behavior. Update the gap analysis with observed runtime evidence. Do not label current source inspection as a visual audit.

## Connected portals and exact UI

**D21 — Confirmed (2026-10-21):** the brand partner table and partner My leads page represent the same brand–creator relationships. Provide separate brand and partner login pages using one identity system, membership-based context selection, and explicit relationship sharing without exposing creator-private CRM data. Reproduce the exact UI from `~/weavdash_assets/weavdash/partner-my-leads.html` and `brand-partner-hub.html`, including their assets and responsive layouts. Palette-only alignment is insufficient. The two dashboard references do not supply login-page designs. This turn authorizes planning/documentation updates only; no application implementation begins. See [portal UI alignment](../m3a/ui-alignment.md) for confirmed requirements, proposed M3A sequencing, visual gates and stage presentation (subsequently settled by D22). This brings basic relationship/hub UI planning forward from M8; advanced PRM, payouts and other backend dependencies remain phased.


**D22 — Confirmed (2026-10-21):** reproduce the partner reference's five visible pipeline columns: Lead, Pitched, Negotiating, Onboarded and Active. Repeat Client and Lost are nice-to-have, optional UI additions for later. Change presentation only; leave existing database tables, columns, constraints, relationships, stored stage values and records unchanged. Existing backend support and aggregate semantics remain intact. This clarifies D21's stage presentation without starting application implementation.


**D23 — Confirmed (2026-10-21):** approve the remaining portal recommendations: matching login form structures with partner burgundy/brand white header variants; role-specific registration creating the corresponding workspace; shared identities and authorized context switching; brand invitations accepted by partners to establish relationships; partnership removal preserving private records; exact reference compositions with honest empty/unavailable states and disabled unsupported automation controls; sample data only for preview/visual fixtures; explicit relationship schema and scoped sharing with migration/permission review and real PostgreSQL isolation tests. Capture reproducible desktop/mobile reference screenshots before UI work. M3A is the approved next milestone before M4. The current request updates planning and handoff only; implementation begins in a subsequent chat on request. See [M3A handoff](../m3a/implementation-handoff.md).

**D24 — Confirmed (2026-10-22):** make the partner portal the single lead-management screen, replacing the separate pipeline page with inline filters/pagination, an Add lead modal and a lead detail drawer. Retain seven-stage backend behavior, Repeat Client/Lost access, existing records, private CRM actions and explicit Start onboarding. Use canonical partner section URLs (`/partner/leads`, `/partner/contacts`, `/partner/outreach-templates`, `/partner/onboarding`, `/partner/media-kit`) with legacy page redirects. Reserve Inbox/Campaigns/Earnings/Autopilot paths while their navigation stays disabled. Place workspace/security, contacts/reminders, outreach templates, onboarding requests and sign out in the navigation avatar dropdown with Lucide icons. Shared account/workspace URLs and existing mutation/JSON contracts remain available. Implementation and local verification are authorized by the user's follow-up requests; production deployment and external actions remain outside scope.

## Email notification scope

**D25 — Confirmed:** Gmail/Outlook mailbox syncing is a nice-to-have for a possible future integration. Required product email notifications must work independently of mailbox syncing. M5 must include working transactional notifications for supported workflows, including closing existing invitation/onboarding notification gaps; an in-app approval inbox alone is insufficient. This requirement does not authorize mailbox access, unattended outreach, real invoice issuance, or unsolicited test messages. See [M5 email requirements and current gaps](../m5/email-notifications.md). Detailed recipients, preferences and delivery behavior remain to be specified; existing notification gaps are not marked complete by this decision.

## Commit, readiness and task continuity

**D26 — Confirmed (2026-10-23):** the user requested committing the current changes, moving toward M6 if possible, and creating a handoff that retains all unfinished, in-progress and deferred earlier milestone work so it can be implemented later. The verified M5 slice is committed; M5 remains incomplete. M6 preparation and a cross-milestone task register implement this request without waiving the existing M1–M5 dependencies or production/external-action approval boundaries. See [current handoff](implementation-handoff.md), [remaining tasks](remaining-tasks.md) and [M6 status](../m6/status.md).


## Platform administration priority

**D27 — Confirmed (2026-10-25):** application-wide platform-owner/super-admin administration is required and must be prioritized before proceeding to the next milestone. Bring forward a required administration work package covering account/workspace management, plans/entitlements, notification/job diagnostics, audit review and controlled support access, with MFA and protected provisioning of a real owner account that survives synthetic-data cleanup. Implementation details remain subject to the [platform administration contract](../administration/contract.md) and existing tenant-privacy boundaries. **ADM-01 is the next implementation task**, before UI-07 and the remaining M5 work; M5 stays incomplete and earlier tasks are retained. This request updates the plan; no admin account, application feature, data deletion or production action is performed by the planning change.

## Backend continuation and contributor scope

**D28 — Confirmed (2026-10-26):** the user assigns administration implementation to Claude and directs Codex to proceed autonomously with incomplete backend sprint/milestone work as the primary priority, followed by necessary desktop/large-screen UI improvements. Small-screen UI testing, optimization and responsive refinements belong to Claude; keep desktop changes compatible with existing responsive design. This authorizes M5-02 backend continuation while carrying pending UI-05 reference/deviation acceptance forward, without declaring it accepted. Preserve existing architecture, design system, tests and unfinished work; avoid unrelated redesign/refactoring. D27's required administration launch gates, unfinished M5/M6 work, provider acceptance and production/external-action boundaries remain in force.


## D29 — Professional Hub and manually activated subscriptions

**Confirmed (2026-11-07):** implement the [Professional Hub contract](../professional/hub-contract.md) before M5-07, including its ADM-01/02/04 dependencies. Preserve creator storage, existing experiences and all release gates. Administrator-supplied quotas/dates/reasons; no checkout or production provisioning.


## D30 — Follow-up delivery and uncertainty operations

**Confirmed (2026-11-09):** the user selected separate MFA-protected platform permissions for sanitized delivery diagnostics/reconciliation, with tenant owners unable to act as global operators. Uncertain messages are never replayed; another send requires an appropriate replacement invitation or fresh draft approval. The user also approved a separate **Send approved message** action for the draft owner's exact approved recipient/content and opt-in review notices only to that owner's verified account. Limits may be configurable; production budget acceptance remains open until provider limits are known. See the [delivery operations contract](../m5/delivery-operations-contract.md). This confirms policy for local implementation; it does not authorize external test mail, deployment, real operator provisioning or waive M5-09/M5-10 and inherited release gates.


## D31 — Administration console scope and MFA policy

**Confirmed (2026-11-16):** the user approved building the administration console from the [design handoff](../administration/design-handoff.md) and the existing approved UI, with no separate returned prototype. The first slice covers work not blocked by policy; suspension, support access and cleanup are shown as unavailable. Suspend/reactivate waits for a decision on the [suspension proposal](../administration/suspension-proposal.md). Platform-operator MFA is confirmed as built: authenticator-app TOTP with offline command-line recovery and no recovery codes. Claude holds primary UI/UX responsibility for the project from this date. See the [console evidence](../administration/console-evidence.md). This closes no ADM package and changes no release gate.


## D32 — Suspension, support access and cleanup policy

**Confirmed (2026-11-17):** the user confirmed the [suspension rules and defaults](../administration/suspension-proposal.md) as written and authorized implementing suspension, support access and cleanup with these answers:

- **Support approval:** a workspace owner approves or declines each request inside their workspace. Platform access alone never opens private records.
- **Support scope:** view-only, limited to the record areas named in the request, ending automatically after at most 24 hours. No edits, downloads or exports.
- **Cleanup:** dry run only. Operators mark synthetic accounts and workspaces into a named batch and generate an exact manifest; no deletion is possible from the console and execution remains a separately reviewed step.
- **Retention:** a workspace holding invoice drafts or payment records is blocked from cleanup, audit history is always kept, and accounts holding a platform grant are always protected. Only explicitly marked records are considered.

**Implementation choice within the support scope (Proposal at the time; Confirmed by D33):** the support view omits free-text notes, template bodies, onboarding answers, documents and payment evidence even inside an approved area. See the [console evidence](../administration/console-evidence.md). This closes no ADM package and changes no release gate; real-environment cleanup and real-owner provisioning keep their separate authorization.


## D33 — Administration follow-up decisions (handoff Part C)

**Confirmed (2026-11-17):** the user answered the eight open questions in the [administration handoff](../administration/implementation-handoff.md#part-c--decisions-from-the-user):

- **Support view content (C1):** keep free-text notes, template bodies, onboarding answers, documents and payment evidence hidden inside an approved area. Specific fields may be proposed later if support cannot do its job.
- **Emergency support access (C2):** none for launch. A workspace with no owner able to approve is handled through the offline procedure.
- **Membership changes (C3):** administration stays read-only for memberships. Workspace owners manage their members.
- **Cleanup execution (C4):** deferred until the retention policy (M3-02) is decided. The console stays dry run only; nothing runs in production without a reviewed backup.
- **Audit export and tenant audit browsing (C5):** not for launch.
- **QR code on enrollment (C6):** approved, including one small client-side QR library. The enrollment secret must be rendered in the browser and never sent to a third party. This authorizes handoff item B7.
- **Health telemetry (C7):** worker, scanner and mail-provider health are left to M6-03 monitoring. The console keeps the scheduler heartbeat only.
- **Owner roster (C8):** the two founders, Cyrus Manatad and Belle Belgar, are the platform owners (`platform.owner`). No other operator or narrower capability is assigned yet. Sign-in identities are kept outside the repository.

**Roster state on staging (read-only check, 2026-11-17):** one active `platform.owner` grant exists, for the first founder, with a verified email and a confirmed authenticator. The second founder has no staging account yet; `platform:grant` requires an existing verified identity, so that grant waits for registration and email verification. Nothing was changed on staging. No production environment exists; production provisioning stays with the bootstrap rehearsal (handoff B13).

**Update (read-only check, later on 2026-11-17):** the second founder registered and verified a staging account, and the first founder granted it platform-owner access from the console. That grant is active but not yet usable: its authenticator is not enrolled.

This closes no ADM package and changes no release gate. C2, C3 and C5 are launch-scope decisions, not permanent refusals; reopening any of them needs a new decision.


## D34 — Workspace-owner MFA rules (M1-04)

**Confirmed (2026-11-17):** the user answered the opening questions in the [workspace-owner MFA handoff](../m1/owner-mfa-handoff.md#decision-needed-before-starting):

- **Recovery:** both routes. One-time recovery codes are shown at enrollment, and a platform operator can reset an owner's authenticator after an identity check when the codes are lost too.
- **Role scope:** the `owner` role only, as D18 states. Admins, managers, members and viewers keep password-and-session sign-in.
- **Challenge:** an enrolled owner enters a code at every sign-in.
- **Authenticator:** one per account. A person who is both a workspace owner and a platform operator enrolls once.

The remaining questions and the platform-grant proposal raised here were settled by D35.

This authorizes no implementation. Code, migrations and tests still wait for the user's explicit go-ahead and the planning phase described in the handoff.


## D35 — Workspace-owner MFA scope and operation (M1-04)

**Confirmed (2026-11-17):** the user accepted five recommendations from the planning phase; see the [contract](../m1/owner-mfa-contract.md):

- **Scope:** every account with an `owner` membership must set up an authenticator. Because the product assigns no other role today, this covers brands, partners and professionals at first entry.
- **Enforcement setting:** always on in production; it can be turned off for staging and the existing test suites. Only the dedicated MFA tests then prove enforcement.
- **Demoted owner:** the authenticator stays enrolled and the code is still asked at sign-in.
- **Identity check before an operator reset:** a written procedure (confirm through the account's verified email and record the reason), not a built feature.
- **Accounts with a platform grant:** no recovery codes and no console reset; the offline reset in D31 remains the only route. This confirms the proposal recorded in D34.

This authorizes no implementation; code still waits for the user's explicit go-ahead.


## D36 — Workspace-owner MFA is off on staging for now

**Confirmed (2026-11-17):** the user chose to run staging with `OWNER_MFA_ENFORCED=false` for now, so the shared reviewer accounts keep password-only sign-in. `compose.staging.yaml` defaults the setting to `false`; setting it to `true` in `staging.env` turns enforcement on. Production is unaffected: it ignores the setting and always enforces (D35). While it is off, staging does not exercise owner MFA; turning it on later needs a decision on the shared reviewer accounts.

## D37 — Brand workspace approval

**Confirmed (2026-11-18):** every new Brand workspace requires administrator approval, whether requested during Brand registration or by an existing account. This supersedes, for Brand workspaces only, D23's rule that role-specific registration creates the workspace. Requests collect Brand name, a website or public business profile, the applicant's role and intended use, with no document uploads. Reviewers approve, request more information or decline, always with feedback the applicant can read; status is shown in the application and outcomes are emailed. A dedicated `brands.review` capability is inherited by platform owners and can be delegated. Existing Brand workspaces keep their access and receive explicit rollout approvals, attributed on staging to the verified platform-owner account named in the agreed plan at the actual rollout time; creation dates, records, memberships and relationships are preserved. Partner signup and Professional activation are unchanged. Approval is not a verified-business badge, a platform privilege or a subscription.

**Execution boundary (Confirmed):** preparation and local implementation are authorized. Staging deployment and the real-account rollout command are separate steps that wait for the user's go-ahead. See the [contract](../administration/brand-approval-contract.md), which separates these confirmed decisions from implementation defaults, the [evidence](../administration/brand-approval-evidence.md) and the [handoff](../administration/brand-approval-handoff.md).


## D38 — Brand lead visibility and Partner records

**Confirmed (2026-11-18):** the user clarified the cofounder's Brand hub request: Lead registrations must show leads associated with the selected Brand across its Partners, with clickable Partner records, shared Partner profile information, related registrations and clickable lead details/current statuses. Unrelated private prospects and private Partner notes stay private. The roster needs server-side pagination and an accessible icon for its existing confirmed End partnership action. `Leads (30d)` counts each Partner's registrations for this Brand in the rolling last 30 days; `Last active` reflects successful Partner sign-in or relevant shared-lead updates; the `Leads registered` card counts all currently Brand-visible registrations across Partners, all dates/statuses, displays 0 when empty and opens the matching list.

**Confirmed handoff request:** prepare a comprehensive handoff for Claude to implement this narrow workstream independently of broader M8 payouts/advanced PRM. This documentation turn does not implement application changes or authorize staging deployment/backfill. The existing M3A private-CRM baseline remains until explicit registration/sharing is implemented. Source company names alone never authorize Brand access.

**Implementation (2026-11-18):** the defaults Claude settled (shared fields, roles, page sizes, lifecycle and sign-in attribution) are recorded in the [contract](brand-lead-visibility-contract.md) as implementation choices, with [evidence](brand-lead-visibility-evidence.md). They are not additional user-confirmed decisions.

See the [Claude implementation handoff](brand-lead-visibility-handoff.md) for scope, observed code gaps, recommended explicit registration/profile/auth design, data preservation, staging considerations and acceptance. Exact optional profile fields, route/schema names, page size, role details and login attribution are marked **Proposal / implementation defaults**, not additional user-confirmed decisions. Preserve existing permissions, MFA, suspension, approvals, entitlements and release gates.
