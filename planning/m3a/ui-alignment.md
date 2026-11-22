# Brand and Partner Portals and UI Acceptance

**Implementation update:** M3A has been authorized in a subsequent request. See [status](status.md), [implemented contract](portal-contract.md) and [evidence](evidence.md). The original planning authority statements below describe their historical requests.

## Confirmed direction

**Review update (2026-10-24):** the current working-tree revision introduces shared account menus/partner chrome, searchable selects, revised dialogs and a new pipeline board. It is not yet accepted; [review findings and evidence](../shared/ui-review-2026-10-24-evidence.md) are tracked as UI-02–UI-05. The new [pipeline mockup](../../mockup/pipeline-claude/pipeline-spec.md) is a **Mockup** design input. Its stage tags and total rules do not silently override the approved stored stages, aggregate semantics or existing complete portal reference. Retain the established fonts and map styling to shared tokens; resolve reference scope and verify the actual production components before marking alignment complete.

**Confirmed (2026-10-23):** across all milestones, destructive actions and actions that revoke access require a modal confirmation using the shared `ConfirmationDialog` and `confirmAction`. Identify the record and consequence; initially focus Cancel, support Escape and keyboard focus containment/restoration, prevent duplicate requests, retain accessible server errors and preserve validation/password/version checks. Render confirmations outside existing forms and lead drawers. Routine saves, navigation and reversible unsaved edits remain immediate; replacement invitations and saves that unpublish shared content revoke access and require confirmation. See [implementation evidence](../shared/confirmation-modals-evidence.md).

**Confirmed (D21, 2026-10-21):** partners in the brand hub are the same creators who use the partner My leads portal. Build two connected views of a brand–partner relationship, separate brand and partner login pages, and reproduce the exact UI of the supplied dashboard references. This request updates planning only; it does not start application implementation or authorize deployment.

## Authoritative visual references

**Mockup / Confirmed:** the following local HTML pages and their adjacent assets are the visual acceptance baseline, superseding the simplified foundation dashboard layout:

- [Partner My leads HTML](../../../weavdash_assets/weavdash/partner-my-leads.html), served at `http://localhost:5501/partner-my-leads.html`.
- [Brand partner hub HTML](../../../weavdash_assets/weavdash/brand-partner-hub.html), served at `http://localhost:5501/brand-partner-hub.html`.
- [Partner screenshot](<../../../weavdash_assets/weavdash/Partner portal – My leads.png>) and [brand screenshot](<../../../weavdash_assets/weavdash/Brand partner hub.png>).
- [Reference instructions](../../../weavdash_assets/weavdash/README.md); preserve the adjacent CSS, JavaScript, SVG artwork, local fonts and license notices when bringing assets into the application.

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

**Confirmed (2026-11-21):** the Media kit / Professional profile page uses the approved compact layout: an editor beside a live public preview with link and figures, a lead-links table with labels, status filters, lead counts and pagination, and a formatted public introduction edited with Tiptap. Formatting is limited to paragraphs, bold, italic, one subheading level, lists and links, and is sanitized on the server on save and on display. Evidence: [media kit](media-kit-evidence.md).

**Confirmed (2026-11-21):** the Outreach templates page uses the approved compact layout: a searchable template list, an editor that inserts and highlights placeholders, and a live draft panel that can fill from a recent lead, copy the draft or open it in an email app. Drafts are never saved. Evidence: [outreach templates](outreach-templates-evidence.md).

**Confirmed (2026-11-21):** the Onboarding requests page uses the approved compact layout: status filter chips with plain status names, a searchable paged request list, a detail panel with Submission, History and Emails tabs, a review box for submitted requests, inline replacement and confirmed revocation, and a New request dialog for any open lead. Evidence: [onboarding requests](onboarding-requests-evidence.md).

**Confirmed (2026-11-21):** the Partner Brand sharing page uses the approved compact layout: summary tiles, a filterable and searchable shared-leads table with icon actions, Connected Brands and shared-profile cards, and a Share a lead dialog that works from the page and opens with the lead chosen when arriving from a lead. Evidence: [Brand sharing](brand-sharing-evidence.md).

**Confirmed (2026-11-21):** the shared Workspaces page (`/workspaces`, opened from the account menu) uses the approved Option B layout: a 28px title with a New workspace dialog, Workspaces and Account security tabs (the security tab is kept in the address and flags a required authenticator that is not set up), workspace cards with Switch and the current hub link, a current-workspace panel with a Rename dialog and described tool links, and security cards for the authenticator, signing out all sessions, data exports and closure. With no workspaces, the create form is shown on the page. Evidence: [workspaces](workspaces-evidence.md).

**Confirmed (2026-11-21):** the account menu offers the same items on every page: while a Partner workspace is selected, it lists Contacts and reminders, Outreach templates, Onboarding requests and Brand sharing, including on Workspaces, Automation, Finance and account pages. Contacts, Outreach templates and Onboarding requests keep the Partner header (My leads, Media kit). Platform staff see the administration link on workspace pages too. Evidence: [account menu consistency](account-menu-evidence.md).

**Confirmed (2026-11-21):** the Add lead dialog uses the approved Option A layout: a compact centred dialog with a fixed header and footer, the lead drawer's field groups (name and value with a currency picker, optional primary contact, optional follow-up with shortcuts and private notes), an optional Share with a Brand box, field-level errors instead of browser bubbles, an "Add lead" submit and a full-screen sheet on phones. Evidence: [Add lead](add-lead-evidence.md).

**Confirmed (2026-11-21):** Brand workspaces follow the same account menu rule: while one is selected, the menu offers Partner hub and Lead registrations on every page. Evidence: [account menu consistency](account-menu-evidence.md#brand-follow-up-2026-11-21).

**Confirmed (2026-11-21):** the lead drawer uses the approved Option B layout: a compact header with stage, value, last update and a confirmed Delete, the Add lead field groups with Stage, onboarding and Brand sharing status rows with real data (starting onboarding opens a dialog), an Unsaved changes footer with Discard and a Save that waits for changes, an Activity tab with type chips and icons, and a labelled read-only view for viewers. Evidence: [lead drawer](lead-drawer-evidence.md).

**Confirmed (2026-11-21):** the lead Activity tab uses the approved Option A layout: a one-line composer that opens the type chips and details (Escape or Cancel closes it), history grouped by day with conversation cards and plain-language change lines (stage names, local next-action times), All, Conversations and Changes filters, and an end-of-history note that mentions the 50-event limit only when it applies. Evidence: [lead activity tab](activity-tab-evidence.md).

**Confirmed (2026-11-21):** the Brand Lead registrations page uses the approved Option B layout: summary tiles, a compact card with stage chips, search, Partner and Registered filters, and a table that shows each lead's summary and one date per cell. A lead opens in a read-only side drawer over the list (kept in the address) with a partner card, summary, contact and timeline. Phones get a Stage select, a Filters button and two-line records. Stages use the pipeline's coloured-dot chip on Brand screens too. Evidence: [Brand lead registrations](lead-registrations-evidence.md).

**Confirmed (2026-11-21):** the Professional Hub uses the approved Option A + Up next layout: a 28px title with Add client lead, four linked summary tiles (open leads by stage, reminders due, onboarding to review, owner-only invoice drafts), an Up next queue of overdue and next-24-hour reminders and onboarding submissions with filter chips, a compact tools card with count pills, a subscription card with usage bars, and CSV export buttons. The Professional header adds Brand sharing to Client work and keeps the brand, Menu and account on one row on phones from 360px. Evidence: [Professional Hub](../professional/hub-ui-evidence.md).

**Confirmed (2026-11-21):** the Automation page (Partner `/automation`, Professional `/professional/automation`) uses the approved Option A layout: a 28px title with New schedule and Write follow-up dialogs; Approvals, Schedules, Email history and Preferences tabs kept in the address; a filterable drafts table whose rows open a side drawer at `?draft=…` for review, editing and confirmed Approve, Send and Discard; one New schedule dialog that explains blocked types; compact schedule and email history tables; and preference switches with an unsaved-changes footer. Evidence: [Automation and approvals](../professional/automation-evidence.md).

**Confirmed (2026-11-21):** Private invoice drafts and Manual payment records use the approved Option A layout: compact headers, per-currency summary tiles, filter chips kept in the address, and tables with balance bars. A draft opens in a side drawer at its existing `/finance/invoices/{id}` address. New draft and Edit use a line editor with exact amounts typed in currency units. Receipts are recorded, allocated (same-currency open drafts, largest amount pre-filled) and reversed (reason, then the shared confirmation) in dialogs. Evidence: [Finance](../professional/finance-evidence.md).

**Confirmed (2026-11-22):** the administration Overview and Accounts use the approved Option A layouts. Overview: a compact 28px header with the observation time, six linked summary tiles, a Needs attention queue with failures first and short links, and recent activity in plain language with named targets. Accounts: one records card with quick-view chips and counts, a search box, dropdowns that apply as chosen, a Joined date panel, one status per row and numbered pages; phones use the shared two-line records and a Filters button. The console keeps 44px targets and its green focus outline. Evidence: [compact administration](../administration/compact-console-evidence.md).

## Table controls and partner avatars (2026-11-19)

**Confirmed (2026-11-19):** controls inside tables and list toolbars use one compact size, `--wd-table-control`: **36px with a fine pointer, 44px below 600px wide or with a coarse pointer**. This covers pagination (Previous, Next, page numbers), table search fields and buttons, row action buttons, rows-per-page selects, standard filter chips and links standing alone in a cell. Use the shared `wd-table-button`, `wd-table-icon-button`, `wd-table-input` and `wd-cell-link` classes rather than per-screen sizes. Form and dialog buttons, primary page actions (such as Invite partners) and the Registered leads filter fields keep their existing sizes.

**Confirmed (2026-11-19):** partners are shown with a decorative initials avatar (`PartnerAvatar`) in the Partner hub table (36px), the Partner record header (48px) and the Registered leads Partner column (28px). The tint is chosen from six palette tints by partnership ID; paused partners are neutral. All text/tint pairs meet WCAG AA (lowest 5.1:1). Avatars use only the consented partner display name; uploaded logos or photos are not in scope. Evidence: [table controls and avatars](table-controls-evidence.md).

**Confirmed (2026-11-20):** the partner pipeline board (My leads) uses one stage filter: chips with counts for All and every stage, replacing the segment buttons and the stage dropdown. Chip counts ignore only the stage filter (`stageCounts`). Search, chips and Due next actions apply as used; there is no Apply button. The board keeps the reference layout beside the sidebar, fits five columns at 1280px (150px minimum), and shows six leads per column before "Show N more". D22's five main columns and the separate Repeat Client/Lost row are unchanged. Evidence: [compact pipeline](pipeline-compact-evidence.md).

**Confirmed (2026-11-20):** the user approved a compact mockup for Contacts and reminders: a contacts table with numbered pagination, sorting, lead-link filtering, row menus and bulk link/export/delete; reminders grouped by urgency with quick snooze; a whole-workspace summary; and CSV export. Phones show one list at a time. Evidence: [compact contacts and reminders](crm-compact-evidence.md).

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

## Pipeline repair reconciliation (2026-10-25)

**Confirmed D22/D24:** retain five primary columns and all seven stored stages, records and aggregate semantics. The [current repair](../m2/pipeline-repair-2026-10-25-evidence.md) restores server-backed filtering/history and truthful counts/totals, exposes Repeat Client/Lost in separate labelled sections, and uses the real production board in the visual fixture. It retains the approved fonts/palette and shared controls; the newer pipeline mockup does not approve alternate storage/lifecycle rules or an always-unfiltered headline. Mobile board/table overflow is contained within each scroll region, and capture/edit dialogs remain reachable with ordinary pointer input.

**Assumption / Open:** functional filters, honest automation state, explicit page-value labels and supplemental-stage sections intentionally differ from the original static reference. Current desktop/tablet/mobile screenshots and comparison notes are retained, but broader explicit reference/deviation acceptance remains UI-05; no exact-parity claim replaces that gate. UI-06 CRM and UI-07 feedback implementation remain next, with inherited manual accessibility/native Safari/zoom reviews separate.
