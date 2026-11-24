# Portal navigation and Home — Claude handoff

Date: 2026-11-24. Baseline inspected: `fff88c0`. Status: implemented locally (uncommitted) on 2026-11-24; see [implementation evidence](portal-navigation-home-evidence.md) for the menu matrix, Home data mapping, deviations, checks and open decisions (color, landing destination). This is a cross-experience workstream, not a new milestone.

## Authority and scope

**Confirmed:** the user wants the v2 navigation/sidebar direction implemented across Brand, Partner and Professional, with Claude implementing and Codex preparing this handoff. The present request authorizes writing the handoff; no application changes were made in this planning session.

**Confirmed:** Home is a new destination with its own context based on the v2 mockup. It must not alias, replace or merely rename the current Partner hub, My leads/Pipeline or Professional Hub. Plan a distinct Home for each experience while preserving those existing destinations and their behavior.

**Confirmed:** existing unavailable navigation items stay visible and disabled in the new sidebar, including on mobile and when collapsed. Brand retains Campaigns, Incentives, Enablement and Payouts. Partner retains Inbox, Campaigns, Earnings and Autopilot. Inventory all three experiences before implementation; preserve any additional existing unavailable entries discovered. Professional has no disabled entries in its current navigation definition; do not invent new ones just to match another experience.

**Confirmed:** unavailable Autopilot remains distinct from the existing functional automation/scheduling/approval tools. Moving navigation must not disable delivered tools or imply that an unavailable feature now works.

**Proposal:** deliver the shared shell first, then the three Home pages. Both are part of this handoff's scope; completion of the shell alone does not complete the workstream. Keep existing content layouts and workflows during the shell change. The Partner detail redesign in the v2 export is a reference for navigation continuity, not authorization to replace existing detail-page fields or behavior.

## References and starting points

- **Mockup:** [Main.dc.html](../../mockup/v2/Main.dc.html), [Partner.dc.html](../../mockup/v2/Partner.dc.html) and [export instructions](../../mockup/v2/README.md). Serve the folder over local HTTP; preserve the runtime files and reference assets. Translate the design into Vue; do not ship the exported React runtime with the application.
- Existing UI requirements: [repository instructions](../../AGENTS.md), [portal alignment](../m3a/ui-alignment.md), [Professional UI handoff](../professional/ui-handoff.md), [Professional contract](../professional/hub-contract.md), and relevant milestone contracts for each reused data projection.
- Existing shells: [FoundationShell](../../src/resources/js/layouts/FoundationShell.vue), [ProfessionalShell](../../src/resources/js/layouts/ProfessionalShell.vue), [PartnerChrome](../../src/resources/js/features/portals/PartnerChrome.vue), [BrandChrome](../../src/resources/js/features/portals/BrandChrome.vue), [FinanceLayout](../../src/resources/js/components/FinanceLayout.vue).
- Navigation/context: [Professional navigation](../../src/resources/js/features/portals/professionalNavigation.ts), [workspace experience](../../src/resources/js/composables/useWorkspaceExperience.ts), [AccountMenu](../../src/resources/js/components/AccountMenu.vue), [routes](../../src/routes/web.php).
- Shared appearance: [foundation.css](../../src/resources/css/foundation.css), [portal-theme.css](../../src/resources/css/portal-theme.css), [professional.css](../../src/resources/css/professional.css), `BrandLogo`, `WorkspaceNotice`, shared buttons, fields, sections and confirmation dialogs.
- **PDF:** [business plan](../../docs/Creator_Brand_Platform_Business_Plan.pdf) remains the business reference. Mockup controls and sample figures do not establish new business rules or approved commercial metrics.

## Review findings

**Mockup:** the desktop design combines a colored top header, approximately 240px white sidebar, icon-and-label links, grouped sections, active-item background, Collapse control and cream main content. Both exported pages share this structure.

**Mockup:** Home includes a tasks strip, a reusable-join-link prompt, Analytics / Activity / Feed views, dashboard controls and filters, KPI cards, pipeline-by-stage and recently updated partners. These are design inputs; sample people, values, counts, relative timestamps and unread badges are not application data.

**Observed in Chromium during the preceding review:** `Main.dc.html` rendered at 1440×900 without horizontal page overflow; header height was 64px and sidebar width approximately 241px. At 390×900, the header wrapped to 168px and the full sidebar stacked above the main content, which started at approximately y=865px. Adopt a mobile drawer rather than copying this stacking behavior. These observations cover Main only; Claude must inspect both references and all implemented experiences. Temporary screenshots were removed after inspection.

**Assumption / Open — color:** the mockup defaults to purple `#641d73`, while current foundation/portal tokens use burgundy `#8e1650`. Retaining burgundy for this layout pass is the recommended default, not a user-confirmed rebranding decision. Preserve Manrope, Bricolage Grotesque, cream `#fbf7ef`, white, mint and existing semantic focus/error colors. Record any intentional reference differences.

## Navigation inventory and route plan

**Proposal:** use one shared shell and sidebar renderer with separate typed navigation definitions for each active workspace experience. Keep the sidebar visible across all of that experience's existing tools and detail pages, not only its landing page. Choose context from the authorized active workspace, not from URL prefixes alone.

| Experience | Home route proposal | Existing working destinations to preserve | Disabled destinations to retain |
| --- | --- | --- | --- |
| Brand | `/brand/home` | Partner hub `/brand`; Lead registrations `/brand/lead-registrations`; their detail routes | Campaigns, Incentives, Enablement, Payouts |
| Partner | `/partner/home` | My leads `/partner/leads` and existing `/partner` entry; Contacts and reminders `/partner/contacts`; Outreach templates `/partner/outreach-templates`; Onboarding requests `/partner/onboarding`; Brand sharing `/partner/brand-sharing`; Media kit `/partner/media-kit` | Inbox, Campaigns, Earnings, Autopilot |
| Professional | `/professional/home` | Professional Hub `/professional`; Client leads `/professional/leads`; Contacts and reminders `/professional/contacts`; Client onboarding `/professional/onboarding`; Brand sharing `/professional/brand-sharing`; Outreach templates `/professional/outreach-templates`; Automation and approvals `/professional/automation`; Profile / portfolio `/professional/profile`; Private invoice drafts `/professional/finance/invoices`; Manual payment records `/professional/finance/payments` | Preserve any actually discovered; none in the current navigation definition |

**Proposal:** also surface Partner's existing authorized `/automation`, `/finance/invoices`, `/finance/payments` and workspace entitlement entry where appropriate after verifying current access contracts. Preserve existing authorized access for every experience; route existence alone does not authorize adding a tool to Brand. Use current truthful finance labels rather than implying payment processing or issued invoices.

**Proposal:** Home comes first. Group the remaining destinations into Work, Outreach, Profile and Finance where useful; preserve Brand's unavailable program entries. Keep a shorter Brand menu rather than adding creator-private tools. Disabled items need a legible label and persistent or otherwise keyboard/touch-accessible explanation such as “Not available yet”; no fake links, `href="#"`, navigation requests or hover-only explanation. A noninteractive row with `aria-disabled="true"` and readable status text is suitable. Keep labels/explanations available in the collapsed presentation.

**Assumption / Open — landing behavior:** the new Home route names above are proposals. Preserve existing login, portal-entry, hub, bookmark and intended-destination behavior by default. Changing the post-login destination or brand-logo destination to Home is a separate explicit decision to record; do not silently redirect established routes. Keep existing terminology unless a rename is agreed.

## Shared shell behavior

1. **Header:** reuse the real `BrandLogo`, show the experience and actual workspace identity, and retain `AccountMenu`. Preserve workspace switching, security/MFA, privacy exports, closure handling, conditionally authorized administration and sign-out. Move duplicated workspace tool links to the sidebar without removing account-level destinations.
2. **Sidebar:** expanded desktop navigation, a collapse/expand button, current-page semantics and scroll access to every entry. Persist only the display preference, with a safe fallback when browser storage is unavailable. Do not put business or authorization state in browser storage. Use tooltips and accessible labels for collapsed icons.
3. **Mobile:** replace the sidebar with a labeled menu button and modal drawer containing the same eligible and disabled items. Support focus containment, Escape, focus restoration, background scroll lock and closing after successful navigation. Reuse existing Reka UI primitives. Select the breakpoint through rendering checks; below 1024px is a starting proposal.
4. **Main content:** preserve one main landmark and the skip link. Keep notices and access/subscription banners in an appropriate position without duplicate banners. Account for existing `containMain=false` and pages supplying their own `<main>`; avoid nested or missing landmarks. Keep table/board overflow inside its scroll region and allow flex/grid children to shrink.
5. **Routing:** resolve active navigation for list/detail URLs, query strings, fragments, canonical finance detail URLs and Professional aliases. Home and existing hub/lead destinations must not both be current. Preserve Inertia navigation and history behavior. Do not leave stale sidebar items after switching workspaces.
6. **Existing actions:** retain invite-partner dialogs, page actions, filters and search. Partner's current header search is a lead search submitting `q`; retain that behavior in a clearly scoped location. Do not relabel it as cross-resource global search.
7. **Visual consistency:** extend existing tokens/classes and shared controls. Keep page-specific navigation and tabs when they represent local sections. Remove only the horizontal primary navigation replaced by the sidebar. Respect reduced motion, readable contrast, 44px touch targets and keyboard focus.

**Proposal:** the initial header omits unsupported global search, Messages, Help and notification badges unless a real existing destination/data contract supports them. If a mockup control is retained as unavailable, make the status explicit. Never show the sample unread count. This does not permit omitting the confirmed disabled sidebar items.

## New Home content contract

**Confirmed:** each experience gets meaningful Home context based on the v2 composition, separate from its existing operational hub. Shared presentation should accept experience-specific, authorized projections rather than copy three dashboard implementations.

**Proposal:** prepare a short per-experience mapping of mockup section → real data source → visibility/permission → empty/unavailable state before coding the Home projections. Reuse existing policies, services and tenant-scoped queries; Home must not become a way to query data hidden in the existing tools.

| Section | Brand context proposal | Partner context proposal | Professional context proposal |
| --- | --- | --- | --- |
| Your tasks | Existing permitted partner/onboarding review work | Existing due reminders and relevant private lead work | Existing client reminders and review work |
| Contextual prompt | Existing invite-partner action where authorized | Existing reusable join-link action where authorized | A relevant existing client/profile action; no automatic assumption that creator/Brand invitation rules match |
| Analytics | Permitted relationship and explicitly registered/shared lead projections | Private lead stages and existing permitted partnership data | Private client lead stages and currently supported client-work summaries |
| Recent records | Partners/registrations the Brand can view | The Partner's permitted records | The Professional's permitted client records |
| Activity / Feed | Show only a supported authorized projection; otherwise clearly unavailable | Same boundary | Same boundary |

**Proposal:** keep the Home composition recognizable: tasks, contextual guidance, view navigation, summary/chart area and recent records. Where there is no approved source for a metric or feature, show a clear unavailable state or supported alternative and record the deviation. Do not substitute zero for unavailable data or claim Home complete with a blank page or copied existing hub.

Preserve all existing stage, count, currency, ownership and aggregate semantics. The mockup's four-stage chart does not replace the application's seven stored stages. Label whether a count measures leads, relationships or registrations; never relabel one as another. Do not invent quarter-over-quarter deltas, commissions, earnings, deliverables, forecasts or a cross-workspace activity feed. Scope task lists to what the current user is permitted to see, including private-owner boundaries within a workspace.

Dashboard creation, “Build with AI”, sharing/publishing analytics, new messaging/notifications and cross-resource global search are not approved new feature implementations. Keep any retained unsupported controls clearly disabled. Filters and refresh controls must operate on the displayed data or be explicitly unavailable. Enable Analytics / Activity / Feed tabs only for implemented content and give working tabs correct keyboard behavior.

**Assumption / Open:** the exact Home content for each experience and any missing data contracts require reconciliation against current capabilities. Claude should implement supported projections, document unsupported sections, and ask only for decisions that materially change business rules or require new feature scope. Prefer existing storage and read-only projections; do not introduce schema migrations or new sharing permissions merely to reproduce mockup metrics.

## Implementation sequence

1. Read repository instructions and the references above; inspect the latest diff and current status. Preserve `.claude/`, `mockup/v2/`, the separate staging-secret-rotation plan and all other unfinished work. Refresh this inventory if the checkout differs from `fff88c0`.
2. Record the final route/menu matrix, Home data mappings, color default and route matching rules. Include list/detail pages and the existing unsupported menu states. Resolve material business-scope decisions before dependent implementation; routine component choices do not require another approval loop.
3. Add reusable shell/header/sidebar/drawer presentation and typed experience-specific navigation. Adapt existing shells as wrappers where useful rather than replacing all page markup at once.
4. Integrate Brand, Partner and Professional tools/details. Keep shared account, public/authentication, workspace selection and platform-administration surfaces in their current shells unless a documented workspace-tool route requires the portal shell. Verify canonical finance and nested detail routes.
5. Add distinct Home routes/pages and minimal scoped backend read projections where needed under existing middleware and permissions. Add meaningful tests for any new data selection or authorization behavior. Preserve existing landing behavior pending the decision above.
6. Remove obsolete duplicated primary navigation and migrated account-menu tool links; preserve all disabled entries, working page search/actions and local tabs. Verify fixtures no longer rely on obsolete headers or selectors.
7. Run the acceptance gates below and write concise implementation evidence under `planning/shared/portal-navigation-home-evidence.md`. Update this handoff's status, the planning/evidence indexes and relevant existing UI handoff links. Do not mark unrelated milestone/release gates complete.

## Verification and acceptance

Run npm/Artisan/Composer commands from `src/`; Compose from the repository root with `--env-file src/.env`. Use a disposable database/Compose project with synthetic identities for mutation-capable suites. The main local database was refreshed from staging in this conversation and is not a test fixture. It has local-only mail, stopped worker/scheduler services and no copied R2 attachment bytes; do not enable external sending or use staging credentials/storage as a shortcut.

- Run `npm run format:changed`, `npm run format:check:changed`, `npm run typecheck`, `npm test` and `npm run build`. Use the pinned formatter and four-space configuration; check explicit committed files if the implementation spans commits.
- Reuse/update relevant existing browser journeys: `portals.spec.ts`, `partner-navigation.spec.ts`, `professional.spec.ts`, `professional-ui.spec.ts`, `brand-lead-visibility.spec.ts`, and affected finance/onboarding/automation journeys. From `src/`, run them against the disposable stack with `COMPOSE_PROJECT_NAME=<disposable-project> npm run test:browser -- <specs> --project=desktop --project=mobile`. Do not weaken workflow assertions to accommodate the new navigation.
- Add focused coverage for distinct Home vs existing hub routes, all three experience menus, active detail/alias matching, collapse preference/fallback, mobile drawer focus and disabled-item behavior. Update shared navigation/account-menu component tests under `tests/Frontend/` as appropriate.
- If backend projections/routes change, run relevant PHP tests and Pint, plus real PostgreSQL RLS/integration checks for Home visibility: wrong experience, cross-tenant/cross-Brand records, private owner records, revoked access, suspension and entitlement restrictions. Existing permission middleware must remain effective for direct URL access.
- Inspect 320×900, 390×900, 768×900, 1024×900 and 1440×900, populated/empty/error states, expanded/collapsed desktop navigation and the mobile drawer. Check wide lead boards/tables, long workspace names, browser zoom/reflow, account-menu access, invite actions and no horizontal page overflow. Visually inspect both mockups and all three live experiences; no exact-parity claim based only on source review.
- Keyboard-check skip link, menu toggles, current-item announcements, collapsed labels, disabled explanations, Escape, focus return and workspace switching. Preserve existing Cancel-first destructive/revoke-access confirmation, reasons/password/version fields, processing guards and server errors.
- Keep screenshots temporary outside the repository and remove them after inspection. Preserve existing evidence images/reference assets. Record commands/results, revision, browser/viewports, component reuse, intentional deviations, supported Home sources and every unverified gate in text.

**Completion requires:** a shared responsive shell across all three experiences and their tools/details; separate meaningful Home pages; preserved disabled menus; preserved business/authorization behavior; passing applicable checks and recorded visual findings. No deployment, feature activation, external messages, staging mutation or live-data cleanup is part of this handoff.

## Planning verification

2026-11-24, `fff88c0`: inspected the v2 exports, existing shell/navigation components, routes and UI planning references. Earlier Chromium Main observations are recorded above; no implementation visual acceptance is claimed. This change adds this handoff and index/current-handoff links only. All handoff/reference links and supplied anchors passed validation; `git diff --check` passed. Application tests/builds were not run for these documentation-only edits.
