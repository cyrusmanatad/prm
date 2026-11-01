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

Unanswered product recommendations remain proposals. Provider selection, sensitive-data collection, real invoice issuance, and external automation must be resolved before dependent features. See [M0 delivery backlog](m0-backlog.md).

## Remaining inspection work

When localhost and browser tooling become available, inspect all seven boards at desktop/mobile widths; exercise state demonstrations; collect console/network errors; verify font/SVG rendering, contrast, focus order, keyboard navigation, and form behavior. Update the gap analysis with observed runtime evidence. Do not label current source inspection as a visual audit.

## Connected portals and exact UI

**D21 — Confirmed (2026-10-21):** the brand partner table and partner My leads page represent the same brand–creator relationships. Provide separate brand and partner login pages using one identity system, membership-based context selection, and explicit relationship sharing without exposing creator-private CRM data. Reproduce the exact UI from `~/weavdash_assets/weavdash/partner-my-leads.html` and `brand-partner-hub.html`, including their assets and responsive layouts. Palette-only alignment is insufficient. The two dashboard references do not supply login-page designs. This turn authorizes planning/documentation updates only; no application implementation begins. See [portal UI alignment](portal-ui-alignment.md) for confirmed requirements, proposed M3A sequencing, visual gates and stage presentation (subsequently settled by D22). This brings basic relationship/hub UI planning forward from M8; advanced PRM, payouts and other backend dependencies remain phased.


**D22 — Confirmed (2026-10-21):** reproduce the partner reference's five visible pipeline columns: Lead, Pitched, Negotiating, Onboarded and Active. Repeat Client and Lost are nice-to-have, optional UI additions for later. Change presentation only; leave existing database tables, columns, constraints, relationships, stored stage values and records unchanged. Existing backend support and aggregate semantics remain intact. This clarifies D21's stage presentation without starting application implementation.


**D23 — Confirmed (2026-10-21):** approve the remaining portal recommendations: matching login form structures with partner burgundy/brand white header variants; role-specific registration creating the corresponding workspace; shared identities and authorized context switching; brand invitations accepted by partners to establish relationships; partnership removal preserving private records; exact reference compositions with honest empty/unavailable states and disabled unsupported automation controls; sample data only for preview/visual fixtures; explicit relationship schema and scoped sharing with migration/permission review and real PostgreSQL isolation tests. Capture reproducible desktop/mobile reference screenshots before UI work. M3A is the approved next milestone before M4. The current request updates planning and handoff only; implementation begins in a subsequent chat on request. See [M3A handoff](m3a-implementation-handoff.md).

**D24 — Confirmed (2026-10-22):** make the partner portal the single lead-management screen, replacing the separate pipeline page with inline filters/pagination, an Add lead modal and a lead detail drawer. Retain seven-stage backend behavior, Repeat Client/Lost access, existing records, private CRM actions and explicit Start onboarding. Use canonical partner section URLs (`/partner/leads`, `/partner/contacts`, `/partner/outreach-templates`, `/partner/onboarding`, `/partner/media-kit`) with legacy page redirects. Reserve Inbox/Campaigns/Earnings/Autopilot paths while their navigation stays disabled. Place workspace/security, contacts/reminders, outreach templates, onboarding requests and sign out in the navigation avatar dropdown with Lucide icons. Shared account/workspace URLs and existing mutation/JSON contracts remain available. Implementation and local verification are authorized by the user's follow-up requests; production deployment and external actions remain outside scope.

## Email notification scope

**D25 — Confirmed:** Gmail/Outlook mailbox syncing is a nice-to-have for a possible future integration. Required product email notifications must work independently of mailbox syncing. M5 must include working transactional notifications for supported workflows, including closing existing invitation/onboarding notification gaps; an in-app approval inbox alone is insufficient. This requirement does not authorize mailbox access, unattended outreach, real invoice issuance, or unsolicited test messages. See [M5 email requirements and current gaps](m5-email-notifications.md). Detailed recipients, preferences and delivery behavior remain to be specified; existing notification gaps are not marked complete by this decision.

## Commit, readiness and task continuity

**D26 — Confirmed (2026-10-23):** the user requested committing the current changes, moving toward M6 if possible, and creating a handoff that retains all unfinished, in-progress and deferred earlier milestone work so it can be implemented later. The verified M5 slice is committed; M5 remains incomplete. M6 preparation and a cross-milestone task register implement this request without waiving the existing M1–M5 dependencies or production/external-action approval boundaries. See [current handoff](implementation-handoff.md), [remaining tasks](remaining-tasks.md) and [M6 status](m6-status.md).


## Platform administration priority

**D27 — Confirmed (2026-10-25):** application-wide platform-owner/super-admin administration is required and must be prioritized before proceeding to the next milestone. Bring forward a required administration work package covering account/workspace management, plans/entitlements, notification/job diagnostics, audit review and controlled support access, with MFA and protected provisioning of a real owner account that survives synthetic-data cleanup. Implementation details remain subject to the [platform administration contract](platform-administration-contract.md) and existing tenant-privacy boundaries. **ADM-01 is the next implementation task**, before UI-07 and the remaining M5 work; M5 stays incomplete and earlier tasks are retained. This request updates the plan; no admin account, application feature, data deletion or production action is performed by the planning change.

## Brand sign-up links

**D28 — Confirmed (2026-11-01):** in addition to single-recipient email invitations, brand owners/admins can share one reusable sign-up link and matching code per brand workspace. Anyone holding the active link or code can register a new account, or sign in to an existing one (which adds a new creator workspace), and join as a partner immediately after email verification and explicit consent. No brand approval step and no recipient-email match applies to this path. Brands can replace the link (the old link and code stop working) or turn it off; existing partnerships stay active. The partner page offers both "Create account" and "I already have an account" so that account existence is not revealed. See the [M3A portal contract](m3a-portal-contract.md).

## Customer terminology and portals

**D29 — Confirmed (2026-11-01):** workspaces have two customer-facing types: **Business** (stored kind `brand`) and **Professional** (stored kind `creator`). "Professional" replaces "creator" in the UI so the product can serve influencers, authors, self-brands, consultants and other individuals. Portals: the **Brand portal** (`/brand`) is for Business workspaces managing their partners; the **Professional portal** (`/pro/*`, formerly labelled the Partner portal at `/partner/*`) is for individuals managing leads, sales and onboarding of converted leads; the future **Partner portal** is for partners invited by a Business, who need only an account and may optionally register their own brand as a separate Business workspace. Pre-rename `/partner/*` URLs redirect temporarily until the Partner portal reclaims `/partner`. Stored kind values, database columns and the outreach merge token `{{ creator_name }}` are unchanged in this pass. Until the [Partner portal contract](partner-portal-contract.md) is approved, invitation and sign-up-link partners continue to join through a Professional workspace (D28).

## Production portal layout cleanup

**D30 — Confirmed (2026-11-01):** production portal layouts prioritize working features over reproducing the reference compositions. This supersedes the exact-reproduction requirement of D21 and the per-section unavailable states of D23 for real account views:

- Navigation lists only working pages. Professional portal: Leads, Contacts, Templates, Onboarding and Media kit, with a menu button on narrow viewports. Brand portal: Partners and Invitations. The account menu shows the name and email inside the dropdown so headers stay on one row.
- Deferred capabilities are kept but compacted into one "Coming soon" strip per portal instead of placeholder cards, toggles and metrics. Summary cards show only real values (Professional: open pipeline, leads, brand partnerships; Brand: active partners, pending invitations, sign-up link status).
- The Workspaces page groups workspaces under Business and Professional with an explicit "Open … portal" action, creates workspaces through a "New workspace" dialog with type choices, and keeps account security in its own panel.
- The synthetic reference fixture (development builds only) keeps the reference compositions for historical visual comparison; it is no longer the acceptance baseline for production layouts.

## Professional closed deals

**D31 — Confirmed (2026-11-01):** the Professional portal shows **closed deals**, not brand partnerships, so Partner and Professional terminology stays distinct even when a won lead is technically a partner. A closed deal is a won lead in the Onboarded, Active or Repeat Client stage; no new stage or data change is introduced. The summary card and sidebar show the count, value per currency and the five most recently updated closed deals, linking to each lead. Brand partnerships of a Professional workspace, including End partnership with confirmation, move to the current-workspace panel on the Workspaces page until the [Partner portal](partner-portal-contract.md) takes them over. Brands still manage partnerships in the Brand portal.

## Partner workspaces

**D32 — Confirmed (2026-11-01):** a third workspace type, **Partner** (stored kind `partner`), holds records linked to brands through an email invitation or a brand sign-up link/code. This supersedes D29's "partners need only an account". Partner workspaces are created only from those flows, never from the New workspace dialog; each account reuses one Partner workspace for every brand. New recipients register at `/partner/register` (allowed only with a retained invitation or sign-up link); existing accounts sign in at `/partner/login`; signed-in users continue in their Partner workspace. Acceptance and joining require a Partner workspace. The Partner portal home at `/partner` lists connected brands with confirmed End partnership, links to join another brand and offers "Register my brand" (a separate Business workspace). Projects, contracts, messages, files and onboarding remain in the [contract](partner-portal-contract.md) for later slices. Existing partnerships were migrated from Professional workspaces to their owner's Partner workspace (`partner_workspace_moves` records each move); consented name snapshots are unchanged, and workspaces without an active owner are left in place. The temporary Workspaces-page partnerships section from D31 is removed. Pre-rename `/partner/leads`, `/partner/contacts`, `/partner/outreach-templates`, `/partner/onboarding` and `/partner/media-kit` still redirect to `/pro/*`.
