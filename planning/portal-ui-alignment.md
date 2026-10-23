# Brand and Partner Portals and UI Acceptance

**Implementation update:** M3A has been authorized in a subsequent request. See [status](m3a-status.md), [implemented contract](m3a-portal-contract.md) and [evidence](evidence/m3a/README.md). The original planning authority statements below describe their historical requests.

## Confirmed direction

**Confirmed (D21, 2026-10-21):** partners in the brand hub are the same creators who use the partner My leads portal. Build two connected views of a brand–partner relationship, separate brand and partner login pages, and reproduce the exact UI of the supplied dashboard references. This request updates planning only; it does not start application implementation or authorize deployment.

## Authoritative visual references

**Mockup / Confirmed:** the following local HTML pages and their adjacent assets are the visual acceptance baseline, superseding the simplified foundation dashboard layout:

- [Partner My leads HTML](../../weavdash_assets/weavdash/partner-my-leads.html), served at `http://localhost:5501/partner-my-leads.html`.
- [Brand partner hub HTML](../../weavdash_assets/weavdash/brand-partner-hub.html), served at `http://localhost:5501/brand-partner-hub.html`.
- [Partner screenshot](<../../weavdash_assets/weavdash/Partner portal – My leads.png>) and [brand screenshot](<../../weavdash_assets/weavdash/Brand partner hub.png>).
- [Reference instructions](../../weavdash_assets/weavdash/README.md); preserve the adjacent CSS, JavaScript, SVG artwork, local fonts and license notices when bringing assets into the application.

The directory is `/home/cyrusadmin/weavdash_assets/weavdash/` (`~/weavdash_assets/weavdash/`). It is an external reference dependency, not currently a versioned application asset. At implementation, retain a reproducible reference snapshot and checksums with visual evidence. Localhost URLs depend on the preview server and are not deployment routes.

Exact reproduction means matching composition, dimensions, spacing, alignment, typography, palette, borders, radii, artwork, controls, card density, and responsive wrapping/overflow at identical viewport sizes. Matching only colors and fonts is insufficient. Use the HTML as the responsive baseline and PNGs as supporting evidence; document any discrepancy rather than silently combining different layouts. Reuse the approved Vue/TypeScript/Inertia stack.

## Portal layouts

| Area | Partner: My leads | Brand: Partner hub |
|---|---|---|
| Header | Burgundy; logo, Partner badge, My leads/Inbox/Campaigns/Media kit/Earnings/Autopilot navigation, search and avatar | White; logo, brand badge, Partner hub/Lead registrations/Campaigns/Incentives/Enablement/Payouts navigation, invite button |
| Heading | Date, personalized greeting, action summary, share lead link and add lead buttons | Partner hub title and supporting description |
| Summary | Four metric cards | Five metric cards |
| Main row | Next-best-action list above pipeline, with right sidebar | Partner table with segment pills and row actions, beside dark attention panel |
| Supporting sections | Autopilot controls and dark brand-partner perks panel | Registered leads beside tiers/incentives; full-width enablement kit |
| Container | 1408px maximum, reference padding/gaps and responsive behavior | 1408px maximum, reference padding/gaps and responsive behavior |

**Confirmed:** reproduce both complete reference compositions. **Confirmed D23:** staged implementation may initially present explicit unavailable/empty states for deferred capabilities, while a synthetic reference fixture exercises every reference section for visual acceptance. Never show sample money, health scores, commission percentages or working-looking automation switches as real account data. Mockup demo handlers are not business logic.

**Confirmed D22:** show only the reference's five board columns: Lead, Pitched, Negotiating, Onboarded and Active. Repeat Client and Lost are optional future UI additions, not required for this milestone. This is a presentation-only change: retain existing database tables, columns, constraints, relationships, stage values and records, with no migration, deletion or reclassification for this decision. Preserve existing backend stage support and aggregate semantics; omitting columns must not delete or move records in those stages. Verify that existing Repeat Client/Lost records remain intact and their existing detail access continues to work. D20's explicit Start onboarding and excluded sensitive collection remain in force despite contrary mockup copy.

## Identity and shared relationship

**Confirmed:** provide separate brand and partner login pages leading to their respective authorized dashboards. One Laravel identity/session system supports both; separate user databases are unnecessary. A person may participate in both contexts. Login entry choice does not create a role, organization membership or sharing grant.

**Proposal:** use `/partner/login` and `/brand/login`, with corresponding portal landing routes. Specify legacy `/login` behavior, safe intended redirects, workspace selection and context switching before implementation. Reuse verification, recovery, throttling, logout/session revocation and required production MFA. Wrong-context and revoked access must offer an authorized selection or deny access without leaking workspace data. The references contain no login designs. **Confirmed D23:** both login pages use the same form structure (email, password, recovery and a link to the other login page), with the partner burgundy header and brand white header, shared typography and design tokens. No exact login screenshot is claimed.

**Confirmed:** keep three boundaries: creator-private workspace, brand-private workspace, and explicitly shared brand–partner relationship. A creator may partner with multiple brands; a brand may have multiple partners. Habi Home's Bea Santos row and Bea's Habi Home partnership must resolve to the same relationship, not disconnected demo records. Partnership is not membership in the other party's private workspace.

**Confirmed D23:** model an explicit relationship between a brand organization and creator organization with invitation/acceptance, status, revocation and audited sharing permissions. Brand-to-partner invitations differ from existing creator-to-brand onboarding capabilities. Private prospects need not be registered organizations; do not auto-link by name/email. Shared projections expose only authorized relationship data. Brand A cannot see Brand B's relationship, or the creator's unshared pipeline, contacts, notes or rates. Partnership termination and workspace membership revocation have distinct lifecycles, both checked by policies/RLS, jobs, exports, caches and files.

Tiers, commissions, registered leads, health and performance belong to the relevant relationship/program and require their own milestone rules. A private lead becomes brand-visible only through explicit registration/sharing. The exact schema, grants, registration rules and migration/backfill plan require design review in M3A; existing onboarding submissions must not automatically create partnerships.

## Approved account lifecycle (D23)

**Confirmed:** partners and brands register through their respective entry points. Partner registration creates a creator workspace; brand registration creates a brand workspace. Existing users reuse their identity rather than creating duplicate accounts and may switch authorized contexts. Brand invitation followed by explicit partner acceptance creates the shared relationship; removal preserves both sides' private records. Implement invitation expiry, replay protection, revocation and duplicate/concurrent acceptance handling. An invitation grants no access before acceptance. Keep existing creator-to-brand onboarding separate.

**Confirmed:** extend the current schema with explicit relationships and scoped sharing; review current code, migrations, backfill and permissions before coding. D22 protects existing pipeline storage/backend support; it does not prohibit the new relationship schema needed for M3A. Capture reference desktop/mobile screenshots before UI work. Unsupported automation controls remain disabled with a brief explanation, and sample data stays in visual-test fixtures/local previews.

**Proposal implementation defaults:** portal route names and the legacy-login destination remain routine M3A contract decisions. Preserve existing creator workspaces and records during backfill; never infer a partnership from an email/name match. Do not add live email delivery as an implicit requirement: local invitation links can be copied for development, with external sends subject to existing authorization.

## Delivery and acceptance

**Confirmed D23 sequencing:** deliver M3A before M4, retaining historical M1–M3 completion evidence. M3A delivers identity/context foundations, relationship lifecycle, both dashboard compositions and reference fixture coverage. M4/M5/M7/M8 progressively supply real finance, automation, campaign/attribution and advanced PRM data. Full brand-hub backend functionality remains phased; the basic relationship and hub UI no longer wait until M8.

| Gate | Required evidence |
|---|---|
| Reference inventory | Snapshot/checksums, asset/font/license inventory and recorded baseline viewport/browser settings |
| Visual parity | Side-by-side screenshots and image differences for both references versus Vue pages using equivalent synthetic data at the same desktop viewport; full-page coverage, not just headers |
| Responsive parity | Compare both rendered pages at 1440px, 1024px and 390px widths with matching heights, fonts and browser settings; verify table/board overflow and sidebar wrapping |
| Login/context | Both login entry points, intended destination validation, multi-context selection/switching, wrong-context denial, verification/recovery/logout and revocation journeys |
| Relationship | Invitation/acceptance/revocation and matching partner identities on both sides; multiple brands/partners and duplicate/concurrent invitation cases |
| Privacy | Real PostgreSQL RLS and policy tests for cross-brand IDs, creator-private records, unauthorized projections, files, exports, jobs and stale/revoked access |
| Honest feature states | Reference fixture data confined to tests/local preview; real dashboards derive data or expose clear empty/unavailable states; no financial or external action simulated as completed |
| Accessibility | Keyboard/focus, readable contrast, zoom, mobile interaction and accessible alternatives; carry forward screen-reader/native Safari release reviews |

No intentional visual deviation passes silently. Record the affected reference, reason, screenshot and explicit acceptance before calling it exact. **Assumption / Open:** a numerical image-difference threshold is not agreed; establish it after controlling rendering variation, with human review of material differences. Existing M2/M3 screenshots demonstrate historical functionality, not acceptance against these references.
