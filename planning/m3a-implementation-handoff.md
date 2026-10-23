# M3A Implementation Handoff

## Status and authority

**Confirmed D21–D23:** M3A implementation is authorized by the subsequent user request. See [current status](m3a-status.md), [implemented contract](m3a-portal-contract.md) and [verification evidence](evidence/m3a/README.md). This document retains the implementation sequence and scope from the planning handoff. Preserve M1–M3 historical evidence and pending manual screen-reader, full zoom and native Safari release checks. No production deployment, hosting purchase or external messaging is authorized.

Read [decisions](decisions.md), [portal UI alignment](portal-ui-alignment.md), [architecture](architecture.md), [database design](database-design.md), [authentication](auth-and-subscriptions.md), [roadmap](implementation-roadmap.md), [M2 status](m2-status.md) and [M3 status](m3-status.md). Root README describes the actual implemented Laravel application under `src/`; root AGENTS.md still contains historical planning-only descriptions. Inspect the actual checkout and preserve existing unrelated/uncommitted work.

## Implementation sequence

| Step | Deliverable | Acceptance |
|---|---|---|
| 1. Inspect and baseline | Review current auth, workspace, pipeline, onboarding, RLS and browser fixtures; inventory local reference HTML/assets, preserve snapshot/checksums/fonts/licenses; render both references before editing UI | Baseline screenshots at 1440px, 1024px and 390px widths with recorded heights/browser/font settings |
| 2. Finalize contracts | Specify portal routes, legacy `/login` behavior, registration/workspace creation, safe intended redirects, context switching, invitation lifecycle, ownership and migration/backfill | Document concrete contracts; existing creator workspaces/data preserved; no inferred partnerships or workspace access from a login choice |
| 3. Identity and relationships | Shared identity/session system; partner/brand registration and login; explicit brand–creator invitation, acceptance and revocation; authorized context selection | Existing users reuse accounts; brand creates brand workspace, partner creates creator workspace; same relationship appears on both sides; removal preserves private records |
| 4. Portal UI | Reproduce both complete reference compositions in Vue/Inertia; login forms share structure with the respective header variants; wire existing features | Match layout, fonts, colors, spacing, artwork, cards, table and responsive behavior; five visible pipeline columns; no lost existing CRM/onboarding functionality |
| 5. Honest feature states | Derive available real data; use empty/unavailable states for deferred finance/AI/PRM; disable unsupported controls with explanations; fixture every reference section | No sample revenue, fake health or simulated successful external action in real accounts; test fixture access confined to development/tests |
| 6. Verify and record | Run appropriate auth/relationship/tenant regression, frontend checks and browser journeys; compare full-page screenshots; update contracts/status/evidence | Real PostgreSQL isolation; visual parity review; no unrecorded deviations; remaining manual release checks accurately carried forward |

## Mandatory boundaries

- Partner board columns: Lead, Pitched, Negotiating, Onboarded, Active. Repeat Client and Lost UI is optional later. Preserve existing database columns/tables/constraints, stage values, records, backend support and aggregate semantics; verify existing detail access for omitted-stage records. New relationship schema is allowed within M3A implementation scope.
- Brand roster partners are the creators using the partner portal. A relationship is not membership in the other party's workspace. Keep unshared pipeline, contacts, notes and rates private; enforce brand-to-brand isolation and safe shared projections.
- Preserve explicit Start onboarding and D20's limited form fields. Existing anonymous onboarding capabilities do not automatically establish a brand account or partnership.
- Login designs: shared email/password/recovery form, link to other portal login, partner burgundy header and brand white header. Reuse verification, throttling, CSRF/session controls, logout/recovery and revocation. Production MFA remains a release requirement.
- Invitation acceptance must resist expiry/replay, wrong recipient, duplicate/concurrent acceptance and revoked access. Context redirects must remain authorized. Define which brand membership roles may invite/remove partners in the contract.
- Capture relationship access tests for policies/RLS, page props, direct URLs, files, exports, workers and caches where applicable. Preserve the existing M2/M3 flows.
- Exact UI references live at `~/weavdash_assets/weavdash/partner-my-leads.html` and `brand-partner-hub.html`, with adjacent assets and PNGs; see [UI acceptance](portal-ui-alignment.md). Preview URLs on port 5501 are optional server conveniences.
- M4 finance, M5 automation, M7 campaigns/attribution and M8 advanced PRM/payout integrations retain their own gates. M3A supplies relationship foundations and complete dashboard compositions, not all depicted backend capabilities.

## Suggested next-chat instruction

> Implement M3A using planning/m3a-implementation-handoff.md and planning/portal-ui-alignment.md. Follow confirmed decisions D21–D23. Inspect the current checkout first, preserve existing work and pipeline database/backend support, reproduce both local reference UIs exactly, and complete the milestone's appropriate verification. Keep deferred features honest and carry forward existing manual release checks.
