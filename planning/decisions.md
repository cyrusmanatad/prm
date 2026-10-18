# Decisions for Approval

## Recorded decisions

**D01 — Confirmed:** creator pipeline and brand onboarding are the first release; manual payment reconciliation initially. Automated transfers and attribution are not launch prerequisites.

**D02 — Confirmed:** Laravel/PHP backend with Vue 3, TypeScript, Inertia.js, Tailwind CSS and shadcn-vue; PostgreSQL with tenant RLS; Redis with Laravel queues and scheduler; Docker Compose and GitHub Actions. Vite builds frontend assets. Previous NestJS/React/BullMQ proposals are superseded. This is stack approval and an instruction to update planning documents, not permission to implement code.

**D04 — Direction recorded:** size the development OVH VPS to project needs. Initial planning recommendation is 2 vCPU / 4 GB RAM / approximately 80 GB SSD; consider 8 GB for shared/heavier development. Private uploads use a Laravel filesystem disk; AWS S3 or OVH Object Storage remains to be selected. No capacity guarantee or provider purchase is approved.

## Major open questions

| ID | Decision needed | Recommendation / consequence |
|---|---|---|
| D03 | Can invited brands participate free, and which data may be shared? | Free invitation participation; explicit collaboration grants; creator contacts/notes/rates private. Directory publication opt-in. User response pending. |
| D04 | Final development VPS plan, storage provider/region, and production budget/region? | Start from the recorded sizing estimate; compare AWS S3 and OVH storage cost, region, and required features. Confirm OS/disk/headroom before purchase; choose production hosting after pilot measurement. |
| D05 | What triggers onboarding: “Won” or another business event? | Keep PDF's seven stages; explicit accepted-deal event starts onboarding before Onboarded. Confirm repeat/lost transitions and whether a prospect may have multiple deals. |
| D06 | What is paid at launch, and have 20–30 committed pilots been recruited? | Start with useful free/pilot entitlements; validate creator pricing before self-serve billing. Define “10 active brands,” grace/downgrade, and trial policy. |
| D07 | Must MVP issue real tax invoices/e-sign contracts, or track records/drafts? | Draft/manual records first; accountant-reviewed invoice issuance and integrated signatures later. This narrows PDF Phase 1 and needs explicit approval. |
| D08 | Is AI pitch drafting a release requirement, and what provider/budget is acceptable? | Optional consent-based pilot behind a flag; template/manual workflow remains usable. Mockup Claude choice is unapproved. |
| D09 | MFA enrollment/recovery, admin coverage, and later enterprise federation provider? | Password/session login is confirmed (D15); require MFA for platform operators and workspace owners before production (D18). Enforcement implementation remains pending. Select federation only when enterprise needs justify it. |
| D10 | Which actions may run without approval? | No automatic external pitches/contracts/financial actions initially; explicit user commands may send an invite. Start with named reminder templates, not rule builder. |
| D11 | What sensitive onboarding data is truly needed and for how long? | Minimize TIN/permits/shipping collection; confirm purpose, document sharing, retention/deletion exceptions, and jurisdiction with qualified advisers. |
| D12 | Later payout funds flow, provider permissions, fees, and attribution rules? | No custody in MVP. Before Phase 2/3 define merchant/payee ownership, payout approvals, unknown outcomes, return windows, link-vs-code precedence, and dispute process. No provider capability/approval is assumed. |

## Implementation authorization

**D13 — Confirmed:** the user authorized implementation one milestone at a time. This supersedes earlier planning-only restrictions, including the historical qualification in D02. Local scaffolding, dependency installation, application code, and verification are authorized within the approved stack. Hosting purchases, live integrations, and production deployment are not part of this instruction.

**D14 — Confirmed:** proceed with development before recruiting the planned 20–30 pilots; validate with pilots later. Pilot recruitment is not a prerequisite for M1. Commercial readiness still requires pilot evidence and business decisions.

**D15 — Confirmed initial defaults:** invited brands participate free through explicit resource-scoped sharing; creator notes/contacts/rates remain private. Financial features track drafts/manual records, with real tax issuance and integrated signatures deferred. AI is optional. Use Laravel password/session login. External actions require approval; an explicit user command may send an invitation. These settle the initial recommendations in D03, D07, D08, D09, and D10, but not provider budgets, production MFA policy, or commercial pricing.

**D16 — Confirmed:** keep the complete Laravel application and its dependencies, tests, build tools, and environment files in `src/`. Documentation/reference directories remain outside `src/`. Repository-level orchestration and GitHub workflow configuration remain at the root. This layout change does not expand M1 scope.

**D17 — Confirmed:** Docker targets stable PHP 8.5 using `php:8.5-cli-bookworm`. Builds pull current base images; the Redis extension is pinned to 6.3.0. The Composer minimum remains PHP 8.3 for local compatibility; this includes PHP 8.5. Container build/runtime verification remains required before M1 acceptance.

**D18 — Confirmed (2026-10-18):** require MFA for platform operators and workspace owners before production. This records the user-approved policy; MFA enrollment, challenge/recovery flows, and enforcement are not yet implemented or tested.

Unanswered product recommendations remain proposals. Provider selection, sensitive-data collection, real invoice issuance, and external automation must be resolved before dependent features. See [M0 delivery backlog](m0-backlog.md).

## Remaining inspection work

When localhost and browser tooling become available, inspect all seven boards at desktop/mobile widths; exercise state demonstrations; collect console/network errors; verify font/SVG rendering, contrast, focus order, keyboard navigation, and form behavior. Update the gap analysis with observed runtime evidence. Do not label current source inspection as a visual audit.
