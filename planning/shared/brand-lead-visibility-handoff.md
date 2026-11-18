# Brand lead visibility and Partner records — Claude implementation handoff

**Date:** 2026-11-18. **Status:** requirements/handoff ready; application implementation has not started. **Confirmed:** the user wants Claude to implement this update. This Codex turn prepares documentation only. Read repository `AGENTS.md`, inspect the current working tree, then implement and verify the feature locally. Staging changes/deployment require their own execution authorization; the earlier presentation-seeding authorization is not a deployment authorization for this feature.

## 1. Outcome and source of authority

**Confirmed — user/cofounder request:**

> Lead registrations - Table of Partners, Hyperlinked names will lead to individual Partner records, personal data of Partners, related list of Lead registrations, Clickable Lead names will display the Lead information.

Documentation checks for this handoff: relative targets in nine changed planning documents and `git diff --check` passed. No product tests or rendering checks were run for this documentation-only task.

The user subsequently clarified and confirmed:

- A Brand sees leads **associated with that Brand across its Partners**, with each lead's status and enough information to understand progress. Unrelated prospects and private Partner notes remain private.
- Lead registrations is a working navigation destination, providing the consolidated Brand-visible lead list. Partner and lead names open their respective records.
- Partner records include shared profile information and their related Brand registrations.
- `Leads (30d)` counts that Partner's registrations for this Brand during the rolling last 30 days, and opens the matching filtered list.
- `Last active` reflects the latest successful Partner sign-in or relevant update to the Brand's registered leads. Show relative time with exact timestamp/activity type available.
- The Partner table needs server-side pagination, total count and page navigation that preserve filters.
- Replace the text `End partnership` table action with an accessible icon button; retain the existing confirmation and permission checks.
- The `Leads registered` summary card counts all currently Brand-visible registered leads across Partners, all dates and all seven statuses. It opens the matching list. Display **0** when there are none.

**Confirmed scope boundary:** this is a separate Brand lead visibility workstream brought forward from broad future PRM scope. It does not implement all of M8, payout providers, revenue attribution, commissions, tiers, health scoring or campaigns. Do not mark those metrics available based on pipeline estimates. Earlier M3A contracts correctly describe the delivered baseline; this follow-up expands explicit sharing, not general cross-workspace access.

**Decision reference:** [D38](decisions.md#d38--brand-lead-visibility-and-partner-records). Tracking: BLV-01–BLV-06 in the [remaining-task register](remaining-tasks.md#brand-lead-visibility-workstream).

## 2. Read first and preserve existing work

1. [Planning index](../README.md), [current handoff](implementation-handoff.md), [roadmap](implementation-roadmap.md), [remaining tasks](remaining-tasks.md).
2. [M3A portal contract](../m3a/portal-contract.md), [status](../m3a/status.md), [UI alignment](../m3a/ui-alignment.md).
3. [Suspension rules](../administration/suspension-proposal.md), [Brand approval](../administration/brand-approval-contract.md), [owner MFA](../m1/owner-mfa-contract.md).
4. [Pipeline](../m2/pipeline-contract.md), [CRM](../m2/crm-contract.md), [presentation dataset](staging-presentation-dataset.md) and [staging evidence](staging-presentation-evidence.md).

Observed starting revision before the combined checkpoint: `27c1a7d`. The user subsequently requested committing the presentation-seeder work and this handoff together. Recheck actual revision/branch and status before editing; preserve the PHP command/service/test, JSON fixture, browser verifier, walkthrough/evidence and planning-index/deployment notes whether committed or still in progress. A local `docs/demo-stg.json` contains the private account-password roster; it is excluded by `.gitignore` and must remain outside commits. Do not stage, relocate or publish private credential artifacts or unrelated files as part of routine feature work. Review the complete file list before staging changes.

Do not create Brand associations by matching company names or email domains, and do not infer sharing from an existing partnership, onboarding submission or public media kit. Existing approvals, memberships, private CRM, entitlements, platform grants and credentials must survive.

## 3. Current implementation and concrete gaps

| Area | Current behavior / starting point |
| --- | --- |
| Brand dashboard | `src/resources/js/features/portals/BrandDashboard.vue`: Lead registrations is a noninteractive span; six roster cells are rendered by one `v-for` as `Unavailable`; lead summary card is also unavailable. Partner names are plain text. Active-partner summary uses `relationships.length`. |
| Server projection | `src/app/Http/Controllers/PortalController.php::index`: loads all relationships with `get()`, no pagination; projects relationship names/status/dates/paused only. Brand `pipeline` is null. |
| Frontend contract | `src/resources/js/features/portals/types.ts`, `src/resources/js/pages/Portal.vue`. Changing shared relationship shape can affect Partner/Professional pages and visual fixtures. |
| Relationship storage | `src/database/sql/portals.sql`: invitations/partnerships, scoped acceptance/revocation functions and RLS. Relationships link Brand and creator workspace IDs, not individual account ownership. |
| Private lead storage | `src/database/sql/pipeline.sql`: creator `org_id`, free-text `brand_name`, contact fields, private notes, seven stages, amount/currency, timestamps/version. No authoritative Brand-workspace registration link. The current company-name label is not a Brand identifier. |
| Other CRM | `src/database/sql/crm.sql`, `src/app/Services/Crm.php`: contacts, links, reminders are creator-private. Do not serialize these to Brands wholesale. |
| Runtime tenancy | `src/app/Tenancy/TenantContext.php`, `src/app/Http/Middleware/WorkspaceContext.php`: restricted non-owner runtime, transaction-local actor/org, membership locks, suspension checks, materialized tenant responses. |
| Authentication | `Auth/SessionController.php`, `Auth/LoginRequest.php`, `Account/AuthenticatorController.php`, `Services/Authenticator.php`, `EnforceOwnerMfa.php`: password, suspension and MFA checks are separate. No durable last-login timestamp was found. Session `last_activity` is not last login. |
| Components | Existing `Pagination`, `DataTable`, `StatCard`, `FilterBar`, `TabNav`, `DetailList`, `ContentSection`, `FormField`, `FieldGroup`, `SelectField`, `StatusBadge`, shared Button and `ConfirmationDialog`. Inspect actual props/styles and reuse/extend them. |

Existing tests to inspect: `tests/Integration/PortalTest.php`; `tests/Acceptance/PartnershipSecurityTest.php`, `PipelineRoleTest.php`, `PlatformGovernanceTest.php`, `OwnerMfaTest.php`; `tests/Browser/portal*.spec.ts`, `pipeline*.spec.ts`, `crm.spec.ts`, `confirmations.ts`; `tests/Frontend/`. Discover exact files with `rg --files`.

## 4. Screen and interaction contract

### Brand hub

Retain approved header, invite flow, shared layout, palette, typography, responsive behavior and unavailable states for unrelated features.

- Partner name becomes an actual navigable link to that Brand–Partner relationship record. Row key remains stable relationship ID; no account-ID-based authorization.
- Render a real 30-day count, linked to registrations filtered by Partner and time window. Render `0`, not unavailable, for no registrations.
- Render real latest activity when known. Unknown is `Not yet recorded`; suspended relationships retain the established Paused presentation without disclosing private suspension reasons.
- Use a Lucide icon appropriate to ending a relationship (recommended `UserRoundMinus` or existing equivalent), accessible name `End partnership with <Partner>`, keyboard/focus support and tooltip. Show only for existing authorized roles. Do not use a trash icon suggesting deletion of the Partner account.
- The icon invokes the existing `confirmAction` / `ConfirmationDialog` path. Identify Partner and loss of shared access; focus Cancel initially, contain/restore focus, support Escape, prevent duplicate submissions, preserve accessible errors. Never bypass confirmation because the trigger became an icon.
- Paginate the roster on the server. Page-size recommendation: 10, allowing the 13-Partner demo to demonstrate two pages. Use existing `Pagination` and explicit total/per-page metadata. Deterministic ordering with a unique tie-breaker; reset pagination on filter changes; preserve filters/back/forward and meaningful empty/error states. Handle a now-empty last page after ending a partnership.
- Global `Active partners`, `All N` and `Leads registered` must derive from authorized full-result aggregates, not the current page length. Preserve the existing accepted/nonrevoked relationship meaning; do not silently reinterpret Paused relationships as deleted.
- Wire `Leads registered` to all visible registrations for the selected Brand. Roster filters/page changes must not accidentally change this global total. Explain any deliberate summary filter semantics in copy.

### Lead registrations page

- Brand navigation works from all new Brand screens and marks the active section correctly.
- Paginated consolidated table: lead display name (link), Partner (link), current stage, registration date and last shared lead update. Recommended optional filters: Partner, stage and literal text search; support the 30-day filter used by roster links. Show matching count and clear filters.
- All seven stored stages remain supported, including Repeat Client and Lost. Counts/status filters use the same backend definitions as details. Counts are registrations, not estimates or sales.
- Partner-filtered navigation, summary-card navigation, detail/back navigation, reload and browser history preserve relevant query state. Unknown/foreign relationship IDs cannot broaden queries or disclose existence.
- State the sharing scope in plain language. No internal SQL/RLS terms in product copy.

### Individual Partner record

- Shared identity/profile, partnership status/accepted date, total visible registrations, 30-day registrations and latest activity.
- Related, paginated list of this Partner's registrations for the selected Brand only; links to lead details and filtered list.
- **Proposal / implementation default:** shared profile consists of display name and optional Partner-supplied business contact name/email, website/public profile URL and short business description. Provide a Partner owner/admin editor with explicit Brand-sharing copy; do not auto-publish account email, phone, address, all workspace members, authenticator/account metadata or private settings. Missing optional fields get a consistent empty state.
- “Personal data” in the cofounder's note does not approve unrestricted account-directory access. Additional sensitive fields require a defined purpose and explicit decision; they are not necessary to ship the core workflow.

### Individual registered lead

- Show only the explicitly shared allowlist: registered display name, associated Partner/Brand, current stage, registration timestamp, latest shared update and optional shared summary/business contact fields selected for sharing.
- **Proposal / default:** read-only for Brand members in this slice. Brands do not move Partner stages, edit private CRM, export all CRM or see private notes, reminders, outreach drafts, rates/value estimates, other Brands, finance, onboarding documents or attachments.
- Private lead updates to allowed shared fields/stage must become visible without manual re-registration; private-note edits must not leak content or advance the shared-activity signal.
- A robust title/display field is required: current private `brand_name` is a free-text prospect company, not necessarily the intended registered lead's name. Provide explicit registered display name; keep original private field semantics intact.

## 5. Explicit registration and data model

**Required:** implement the producer flow on the Partner side as well as Brand read screens. Empty read screens backed by no registration workflow do not finish the feature.

**Proposal / recommended design:** a dedicated registration/share record references a stable partnership ID, creator workspace ID and source pipeline-entry ID, with immutable `registered_at`, state/version, registering actor and an explicit shared-field projection. Keep source private ownership intact. Add minimal relationship-scoped shared profile storage if needed. Claude may choose different table/function names with the same guarantees and record the final schema/API contract.

- Partner chooses an active, unsuspended connected Brand from authorized relationships and explicitly registers a selected private lead; explain the fields being shared. Brand association is an identifier, never free-text matching. Existing leads remain private until that action occurs.
- **Proposal / default permissions:** Partner owner/admin may register, edit the shared profile/summary and withdraw registration. Existing source-lead editors retain their normal CRM permissions; permitted source-stage edits update an already-authorized shared projection. Active Brand workspace members may read the allowlist; existing owner/admin permissions govern ending partnerships. Test each role rather than assuming role names confer cross-workspace access.
- Enforce uniqueness/idempotency for a source lead within a relationship. Repeated POSTs cannot duplicate counts or refresh the original registration date. Distinct records are distinct registrations; do not infer person/company deduplication across Partners.
- **Proposal / default:** a source lead may be registered to more than one relationship only through separate explicit actions and isolated projections. If implementation chooses one-Brand-only registration, document that constraint rather than silently transferring/replacing existing sharing.
- Treat withdrawal as access revocation: confirmation, record/version checks, audit and atomic state change. A re-share must not reset 30-day counts to make an old registration appear newly acquired; preserve first registration time. A new accepted partnership is a new authorization context and never automatically revives an ended relationship's shares.
- Source deletion must withdraw/hide the registration in the same transaction; avoid dangling links or stale Brand-visible snapshots. Decide tombstone/foreign-key behavior and prove rollback preserves source plus share state.
- Relationship termination or either-side suspension immediately prevents detail/list/count/activity access to affected shares under existing suspension rules. Paused relationship metadata can remain visible. Ended/withdrawn/inaccessible registrations are excluded from the current Brand-visible total; do not retain a count that discloses inaccessible records.
- Keep existing entitlement/subscription enforcement and source-resource usage accounting. Registration does not create another private lead or authorize a new commercial quota/plan assignment. Shared read endpoints must not bypass suspended/expired Professional access rules; preserve existing creator-workspace relationship eligibility rather than converting Professional workspaces or silently dropping their accepted relationships.
- No production migration automatically publishes existing private leads or profile fields. Additive migrations preserve existing IDs/data and add indexes/constraints. Cover rollback semantics honestly once registrations exist.

### Metrics and timestamps

**Leads registered card:** count distinct eligible active registration records for the selected Brand across all authorized Partners, all dates/stages. Exclude withdrawn/deleted-source/ended or inaccessible relationship records. Apply the identical visibility predicate in totals, lists and details. Use the same transaction/read basis so a response is internally consistent.

**Leads (30d):** same visibility predicate, filtered to a Partner and `registered_at >= now - 30 days` and `registered_at <= now`, using one server UTC clock. Recommended window is a rolling 30×24 hours; localize display only. Source lead creation may predate registration. Exactly at the lower boundary counts; future timestamps do not count. Stage moves/ordinary edits cannot reset the window. This is a registration count, not how many leads changed status recently.

**Last active:** maximum of eligible successful Partner sign-in and relevant Brand-visible registration/update timestamps; return timestamp and event category (e.g. Signed in / Lead registered / Lead updated). Store UTC and localize/format with existing utilities. Specify deterministic tie behavior. Do not substitute relationship acceptance, session requests, queued jobs or arbitrary private `pipeline_entries.updated_at` for activity.

**Proposal / recommended login attribution:** record a successful authentication only after all applicable account-suspension, verification and MFA checks, then attribute it to the creator workspace selected in that sign-in's authorized entry flow. Use a bounded session marker to avoid turning every page visit into a login. Do not credit every workspace the identity belongs to, Brand/admin sign-ins or operator support impersonation. No IP, session ID, device list or other private account activity is exposed to Brands. For multiple members, aggregate eligible workspace sign-ins without exposing a member directory. Document the chosen attribution rule before coding.

`Auth::attempt` emits a login event before the custom suspended-account check and before a later MFA challenge. A naïve listener there would falsely mark blocked/incomplete sign-ins as activity. Cover both enforced and staging-disabled MFA, challenge, recovery-code and ordinary sign-in paths; preserve their security behavior. Use an actor-bound narrow write path under the existing restricted role, not a global runtime update grant.

For existing records, preserve truthful known shared activity; otherwise show `Not yet recorded` until a qualifying event. Do not invent historical login dates from session presence or registration creation. Record shared-change timestamps only for published-field changes; private notes and unrelated Brand leads cannot advance another Brand's signal.

## 6. Authorization and query engineering

- Brand reads run under the selected Brand workspace and restricted runtime role. Keep current creator-private table policies intact. Never switch tenant context to a Partner workspace to bypass its membership boundary.
- Use narrowly scoped shared tables/projections, RLS and/or reviewed SECURITY DEFINER functions with fixed search paths, minimal EXECUTE grants, actor/current-workspace/relationship checks and field allowlists. `users`, `pipeline_entries`, contacts and security/account columns must not become broadly readable.
- Enforce all predicates for HTTP, JSON, Inertia partial requests, counts, links, filters, direct URLs and any newly introduced functions. UI-only hiding is insufficient. Wrong-context and forged IDs need generic denied/not-found behavior.
- Review suspension, membership revocation, relationship revocation and source mutation lock ordering. Prove that a winning revocation blocks a waiting publication/read as required by existing membership transaction rules; do not add a time-of-check gap between authorization and shared write.
- Keep authorization/materialization inside `WorkspaceContext` transactions. No deferred tenant queries after the middleware returns. Preserve private cache/session headers; do not cache cross-Brand projections under a source-ID-only key.
- Batch aggregate queries and paginate in PostgreSQL; avoid N+1 lookups and serializing an entire private pipeline to compute counts in PHP/JavaScript. Index relationship/Brand scope, source identity, registration state/date and listing sort keys as needed.
- Audit registration, allowed shared-field changes, withdrawal and relevant profile changes using IDs/event names, not private contact payloads or credentials. This feature does not send new email or add delivery schedules.

### Proposed routes and response contract

Names are implementation defaults, not pre-existing routes:

| Route | Purpose |
| --- | --- |
| `GET /brand` | Existing hub, paginated Partner roster plus independent full-result metrics |
| `GET /brand/partners/{relationship}` | Partner record and related registrations |
| `GET /brand/lead-registrations` | Consolidated authorized list, filters and pagination |
| `GET /brand/lead-registrations/{registration}` | Read-only shared lead detail |
| Partner-scoped POST/PATCH/withdraw endpoints | Explicit publish/update/withdraw workflow under existing creator workspace context |

Use separate roster/list props such as `partners`, `partnerPagination` and `metrics` rather than silently changing every consumer of `relationships`. If replacing that prop, migrate Partner/Professional consumers and fixtures together. Paginated list metadata should expose page/per-page/total; include global counts separately. Document exact query keys, allowed sorts, filter validation, response fields and mutation versions in the implementation contract/API notes. Do not collide with existing `/brand/requests/{brandRequest}` approval routes.

## 7. Existing staging presentation data

The presentation dataset currently has one Northline Living Brand, 13 Partners, 273 private leads, USD, all seven stages, linked contacts/reminders and 90-day entitlements. It was seeded at **2026-11-18 04:51:24 UTC** on app/web images `c7cbb57`. Recheck live state before any later authorized staging action.

- Existing fixture has one prospect-company entry labelled Northline Living per Partner, plus unrelated companies. These are **not registered shares**. Do not expose all 273 leads to make the demo card look populated.
- Initial seed lead creation dates are 34–60 days before the seed date; recent activity is separate. A new explicit registration's actual timestamp drives the 30-day metric; do not rewrite source creation dates or backdate approvals/sign-ins.
- Do not modify `northline-living-v1.json` in place: the seed command verifies its stored checksum and preserves edits/credentials. Any demonstration registration provisioning needs a separately versioned, idempotent, staging-only manifest/command with explicit source and target IDs and presentation provenance, reviewed before execution. A suggestion is to register the 13 explicitly selected Northline entries, yielding 13 shared registrations, while leaving the other 260 private; this is a **Proposal**, not an instruction to run it now.
- Use disposable synthetic fixtures for implementation tests, including recent/old registrations and sufficient Partners to cross pagination boundaries. Do not run destructive fixtures or migrations against staging during development.
- Private credentials are outside Git; see [dataset evidence](staging-presentation-evidence.md). Preserve the existing accounts, fixture registry, password roster, approval provenance and entitlement dates.

## 8. Implementation sequence and deliverables

1. Inspect working tree, current UI/components, schema/RLS/auth paths and existing tests. Record final metric, profile-field, lifecycle and login-attribution defaults in a companion `brand-lead-visibility-contract.md`; distinguish confirmed behavior from implementation choices. Resolve only material unresolved sharing questions; routine technical choices do not need another user approval round.
2. Add additive schema, narrow runtime grants/policies/functions, versioned registration producer flow and audit. Preserve current private API behavior. Establish tests with two Brands, multiple Partners, shared/unshared records and all roles.
3. Implement the shared read projections, consolidated list/detail and Partner record, with real aggregates and stable pagination. Implement reliable eligible login/shared-update timestamps.
4. Wire the Brand hub count, roster metrics, links, pagination and accessible icon action; reuse approved components. Add optional shared-profile authoring with clear Partner-side visibility.
5. Run backend/isolation/concurrency, frontend and browser acceptance. Inspect actual desktop/phone rendering. Fix behavior/design/accessibility issues before handoff.
6. Write concise `brand-lead-visibility-evidence.md` with actual revision, environment, commands/results, viewports and unresolved gates. Update planning/evidence indexes, this handoff status, task rows and affected architecture/database/API/identity documentation. Prepare staging migration/grant/backfill runbook and rollback limits; no staging execution is implied by local completion.

## 9. Acceptance matrix

| Area | Required proof |
| --- | --- |
| Registration producer | Existing lead stays private until explicitly registered. Only eligible Partner roles/relationships can publish; foreign IDs denied. Duplicate/concurrent/retried requests create one registration and preserve first registration date. Stage/projection writes commit or roll back together. |
| Isolation | Brand A sees only its own authorized registrations/profile projection. Brand B, another Partner, a revoked member, suspended account/workspace and anonymous caller cannot read the wrong records. Existing direct private CRM/table access remains denied under real PostgreSQL RLS. Cross-Brand filters, counts, partial props and forged source/relationship IDs cannot leak records. |
| Lifecycle | Withdrawal/source deletion/partnership termination remove shared access and totals atomically while preserving remaining private records. Rejoining does not silently republish. Exercise real locking/revocation orderings, stale versions and rollback. Paused rows do not disclose protected details/activity. |
| Profile/detail | Shared field allowlist only, empty optional fields graceful, unsafe URLs rejected/handled with existing conventions, no private note/contact/security/finance payload in HTML/JSON/partial props. Brand reads do not gain source mutation rights. |
| Counts | No records → 0. Different Partners/Brands, all seven stages, old source/new registration, exactly 30-day boundary, just outside, future timestamps, duplicate joins, withdrawals and paused/ended relationships. Card equals the unfiltered accessible list total across all pages. Row link produces its exact 30-day count. |
| Pagination | 0, 1, page-size, page-size+1 and 13+ Partners; stable tie sorting, correct total on every page, no duplicates/missing rows for unchanged data. Search/filter/history/reload and deletion of last-page row handled. Lead lists also paginate; summary cards do not shrink to page length. |
| Last active | Fully completed login updates appropriate workspace; failed password, suspended sign-in, failed/pending MFA, other-workspace sign-in, generic requests and support access do not. Relevant shared change updates only its Brand signal; unrelated leads/private-note changes do not. Unknown/old/tied timestamps and MFA recovery paths covered. |
| UI actions | Partner/lead links, card/count drill-down, filters, empty/loading/error/disabled states, cancel/confirm partnership action, duplicate prevention and focus restoration work by mouse and keyboard. |
| Compatibility | Partner/Professional private lead pages, seven stages, onboarding, invitations/join links, Brand approvals, MFA, suspension/support access and entitlements retain their existing behavior. Shared TypeScript prop consumers and visual fixtures remain valid. |
| Responsive/accessibility | Inspect hub, Partner detail, registration list/detail and producer flow at 1440×900 and 390×900; check 320px where touched. No document overflow; bounded tables remain keyboard/touch scrollable. Accessible labels, linked fields, focus/dialog behavior, contrast and appropriate touch targets. |

Run npm/Composer/Artisan from `src/`; Docker Compose from repository root with `--env-file src/.env`. Use fresh disposable PostgreSQL plus actual `weavdash_app` runtime and migration-control credentials for isolation; SQLite is not RLS acceptance. Inspect prerequisites in [README](../../README.md).

Expected gates, selected to match the final changes:

```sh
# From src/
npm run format:changed
npm run format:check:changed
npm run typecheck
npm test
npm run build
vendor/bin/pint --test --dirty
vendor/bin/phpunit
vendor/bin/phpunit -c phpunit.pgsql.xml
vendor/bin/phpunit -c phpunit.acceptance.xml --filter '<new feature classes and affected existing classes>'
COMPOSE_PROJECT_NAME=<disposable-project> npm run test:browser -- <new-and-affected-specs> --project=desktop --project=mobile
```

Also run schema/catalog/runtime-grant checks and migration/rollback verification appropriate to changed SQL, plus the existing secret scan and link validation. The PHP suites must use the compatible PHP 8.5 container/current lockfile environment; host PHP 8.3 cannot load current dependencies. Run Chromium and available Linux WebKit as prescribed by existing gates, reporting unavailable engines explicitly rather than claiming native Safari coverage.

Use temporary screenshots outside Git only, inspect and remove them. Keep concise text evidence in `planning/shared/`, not a parallel Markdown tree under `planning/evidence/`. Passing Prettier alone does not establish UI acceptance.

## 10. Handoff completion and remaining decisions

**Confirmed deliverable for this session:** documentation and linked implementation scope, no product changes or staging execution.

**Implementation defaults above are proposals**, not claims that the user already selected exact schema/route names, page size, optional profile fields, role matrix, multi-Brand cardinality or workspace-login attribution. Claude can use these conservative defaults and record the final contract while implementing. Do not stall for confirmation of routine choices. Ask only if completing the intended behavior requires broader personal-data disclosure, private-field sharing, a conflicting lifecycle rule or other material product decision not covered here.

At local completion report actual tests/rendering, migration/grant changes and remaining release/staging gates. Keep M8 payouts/advanced PRM and inherited M1–M6 acceptance boundaries open. Do not claim this handoff itself implemented any feature.
