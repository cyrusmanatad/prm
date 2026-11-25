# Portal navigation and Home — implementation evidence

Date: 2026-11-24. Baseline: `fff88c0` plus uncommitted working tree. Scope: [handoff](portal-navigation-home-handoff.md). Not committed, not deployed; no staging or live data touched.

## Delivered

- **Shared shell:** `layouts/PortalShell.vue` (header, sidebar, mobile drawer) with `features/portals/PortalNav.vue` and typed definitions in `features/portals/portalNavigation.ts`. `ProfessionalShell`, `PartnerChrome`, `BrandChrome` and `FoundationShell` are now thin wrappers, so every existing page keeps its markup and layout. Styles: `resources/css/portal-shell.css`, built on foundation tokens.
- **Context:** chosen from the authorized active workspace (`activeWorkspace.kind/experience`), not URL prefixes. The presentation-only shared prop now also carries the workspace `name` and the account's `role` (`HandleInertiaRequests`); the role only hides owner-only links; routes still authorize every request.
- **Home:** `GET /{brand|partner|professional}/home` (`HomeController`, `Services/PortalHome`, `pages/Home.vue`, `features/home/HomeView.vue`, `resources/css/home.css`). A Professional workspace opening `/partner/home` is redirected to `/professional/home`, like its other tools; any other experience mismatch is 404.
- **Reused:** `BrandLogo`, `AccountMenu`, `WorkspaceNotice`, `Tabs`, `EmptyState`, `.wd-stat-card`, `.wd-data-table`, Reka `Dialog` and `Tooltip`, `money()`, `changeText()`, `relativeTime()`, `Crm::summary`, `ProfessionalHub::upNext` and `LeadRegistrations` metrics, summary, facets and listing. One query was added: `LeadRegistrations::recentActivity` (the shared allowlist only).

## Route and menu matrix

| Experience | Home | Working items (in order) | Unavailable (retained, never links) |
| --- | --- | --- | --- |
| Brand | `/brand/home` | Partners: Partner hub `/brand` (+ `/brand/partners/*`), Lead registrations `/brand/lead-registrations/*` | Programs: Campaigns, Incentives, Enablement, Payouts |
| Partner | `/partner/home` | Work: My leads (`/partner/leads`, `/partner`, `/pipeline`), Contacts and reminders (`/crm`), Onboarding requests (`/onboarding-requests`), Brand sharing. Outreach: Outreach templates, Automation and approvals `/automation`. Profile: Media kit (`/public-presence`). Finance (owner only): Private invoice drafts `/finance/invoices/*`, Manual payment records, Pilot entitlements | Inbox (Work), Campaigns and Autopilot (Outreach), Earnings (Finance) |
| Professional | `/professional/home` | Professional Hub `/professional` (exact), then the existing ten tools; legacy `/partner/*`, `/finance/*` and `/automation` addresses resolve through `professionalDestination` | None (none existed) |

Matching ignores query strings and fragments; the longest matching prefix wins, so exactly one item is current, and Home and a hub are never both current. Workspace selection, account, public, Brand request and administration pages keep their existing shells. Brand workspaces get no creator tools, including finance routes. Partner finance links follow the existing owner-only access contract. Professional keeps its existing finance visibility.

## Home data mapping

| Section | Brand | Partner / Professional | Unavailable or omitted |
| --- | --- | --- | --- |
| Your tasks | Pending invitations (owners/admins only, otherwise `null`), registrations updated this week, registered in 30 days | Overdue/next-24h reminders and submitted onboarding (`upNext`), leads with a due next action | — |
| Prompt | Invite partners → Partner hub (managers only) | Media kit lead link / Profile lead link (published state decides the wording) | Dismiss is a per-browser display preference |
| Analytics | Active partners, registered leads, 30-day registrations, partners with leads; registered leads by the 7 stored stages | Open leads (all stages but Lost), open value per currency (never summed across currencies), reminders due in 7 days, onboarding to review, Partner-only Brand partnerships; leads by all 7 stages with per-currency totals | Quarter deltas, deliverables, date/owner/platform filters, dashboards, Build with AI, Share |
| Recent records | 5 newest registrations → registration detail | 5 most recently updated leads → `?entry=` drawer | — |
| Activity | Registration and shared-detail events | Pipeline activity; conversation kinds name the kind only, and their text stays in the drawer | — |
| Feed | Shown as text “Feed · Not available yet”, outside the tab list | Same | No approved source |

Refresh reloads only the `home` prop. All queries run in the request's tenant context under existing RLS; no migrations, permissions or sharing rules changed.

## Decisions and deviations

- **Assumption / Open — color:** burgundy `#8e1650` retained, not the mockup's purple. Active item uses blush `#f6e3ec`.
- **Confirmed D23 retained:** Brand keeps its white header; Partner and Professional use burgundy.
- **Landing unchanged (Open):** sign-in, `/portal/enter`, `/partner`, hub and logo destinations are unchanged (the Professional logo still links to the Hub, and the others are unlinked). Making Home the landing page remains a separate decision.
- **Header:** omits global search, Help, Messages and notifications. Partner keeps its existing lead search (submits `q` to My leads), now labelled “Search leads”; it moves to its own row below 768px.
- **Account menu:** inside the portal shell, workspace tools are left to the sidebar. On pages outside it (Workspaces, account and privacy pages) the confirmed 2026-11-21 tool list remains.
- **Mobile (<1024px):** labelled Menu button opens a Reka modal drawer (focus containment, Escape, focus return, scroll lock, closes after navigation). The full sidebar is not stacked.
- **Collapsed sidebar:** icon-only, labels kept as screen-reader text and tooltips; unavailable rows become focusable in this mode only, so their explanation is reachable. Preference stored under `weavdash.portal.sidebar`, with a fallback when storage is blocked.
- **Wide content:** with the 240px sidebar, Partner My leads at 1440px scrolls its board inside the board region (no page overflow).

## Verification

All commands ran on 2026-11-24 against the working tree. Browser and PostgreSQL checks used a disposable Compose project, `weavdash-nav` (fresh volumes, synthetic identities, app on `:8010`), which was removed afterwards. The staging-refreshed main database was not used.

| Gate | Result |
| --- | --- |
| `npm run format:changed` / `format:check:changed` | 34 changed frontend files pass |
| `npm run typecheck`, `npm run build` | Pass |
| `npm test` (Vitest) | 40 files, 244 tests pass, including new `PortalNavigation.test.ts` (menus, unavailable rows, owner filtering, current item for list/detail/alias/query/fragment URLs, collapse persistence and storage fallback, drawer Escape and focus return, close after navigation, account menu inside the shell) and `Home.test.ts` |
| Pint | Pass |
| `phpunit.pgsql.xml` (integration) | 44 tests, 873 assertions pass (`InertiaWorkspaceTest` updated for `name`/`role`) |
| Acceptance (runtime role, real RLS) | New `HomeVisibilityTest` 4 tests, 129 assertions: separate Home vs hub routes and redirects, wrong experience, cross-tenant leads and reminders, Brand never sees private pipeline or unshared leads, viewer gets `null` invitations, other Brand sees nothing, withdrawn registration disappears, revoked member, non-member and suspended workspace are denied. Also passing: `BrandLeadVisibilityTest`, `ProfessionalHubTest`, `RoleAuthorizationTest`, `FinanceIsolationTest`, `PartnershipSecurityTest`, `CrmRoleTest`, `PipelineRoleTest`, `JoinLinkTest`, `PilotEntitlementTest` |
| Identity suite (`artisan test`, container PHP 8.5) | 53 tests pass |
| Playwright Chromium desktop + mobile | 13 existing specs updated for the sidebar or drawer (portals, partner-navigation, professional, professional-ui, brand-lead-visibility, finance, onboarding, outreach-templates, automation, crm, pipeline-filtering, accessibility, ui-refinement): 47/48 on the first run. The failure was the 320px Professional header badge spacing, which was fixed, after which professional-ui, partner-navigation and portal-home passed (16/16). New `portal-home.spec.ts`: 6/6 on the final build. Workflow assertions were not weakened; nav selectors now use the shared `portalNavigation` helper |

**Visual inspection (temporary screenshots, deleted):** both v2 mockups at 1440px (and Partner at 390px), plus Partner Home at 320, 390, 768, 1024 and 1440px (expanded and collapsed), the Partner drawer at 390px, Partner My leads at 1440 and 390px, Contacts at 1024px, Finance at 1440px, Media kit at 768px, Professional Home at 1440 and 390px, Professional Hub and Client leads at 1440 and 1024px, and Brand Home at 1440, 768 and 390px with a long workspace name, Brand hub at 1440 and 390px and Lead registrations at 1024px.

Findings:

- The shell matches the mockup structure: a 64px header, a 240px white full-height sidebar with grouped labels, a tinted current item and Collapse at the bottom.
- No horizontal page overflow at any inspected width. The Partner board scrolls inside its own region, and the Professional Client leads side panel stays beside the board at 1440px.
- Long Brand names truncate in the header and wrap on Home.
- Fixed during inspection: sidebar background height, task separator spacing, 44px task and “View” targets, 320px tab and stage-row wrapping, and the 320px header.

**Not verified:** WebKit/Safari, screen readers, browser zoom beyond responsive widths, and the full browser suite beyond the listed specs. The tooltip in the collapsed sidebar was checked only in component tests; the screenshot did not capture it. Commit, PR and CI remain open.

**Open decisions:** retain burgundy or adopt the mockup's purple, and whether sign-in or the logo should land on Home.
