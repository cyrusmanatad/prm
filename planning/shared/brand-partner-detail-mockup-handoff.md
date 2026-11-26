# Brand Partner detail — analysis and mockup handoff for Claude

Date: 2026-11-25. Source baseline inspected: `cce4871`, plus existing local changes. Status: **Mockup** approved by the user on 2026-11-26 — [prototype](../../mockup/brand-partner-detail/index.html) and [design review](brand-partner-detail-mockup-review.md); production redesign merged to `main` at `54fb7ef` ([cyrusmanatad/prm#101](https://github.com/cyrusmanatad/prm/pull/101)) on 2026-11-26 ([evidence](brand-partner-record-evidence.md)); not deployed.

## User request and authority

**Confirmed:** the user finds the current Brand-facing Partner detail UI generic and wants Claude to analyze it and generate a mockup first. Target: `http://localhost:8000/brand/partners/072201d3-c88d-5a7b-ad3c-26c607935bf9`.

**Confirmed:** this stage delivers analysis and a reviewable design artifact only. Do not modify the production Vue page, shared production components/CSS, routes, database, business rules or live records. Do not implement the proposed redesign, open an implementation PR, merge or deploy it before the user reviews the mockup. Deployment from the earlier conversation remains paused.

## Role and working approach

Act as a senior UI/UX designer and frontend engineer experienced in SaaS relationship-management applications. Use senior Laravel/PostgreSQL backend engineering judgment to understand the existing data contract, permissions and tenant boundaries; backend implementation is outside this stage. Apply relevant installed frontend/design skills after reading their instructions, and identify the skills actually used in the review notes. Role descriptions do not replace repository requirements.

Analyze the actual screen, its purpose and its user's next tasks before composing a design. Produce one coherent recommended direction with a brief rationale rather than a generic dashboard template or several superficial variants. Preserve the established WeavDash design language while improving hierarchy, density, grouping, orientation and scanning.

## Required references

- [Current page](../../src/resources/js/pages/BrandPartner.vue), [controller](../../src/app/Http/Controllers/BrandLeadController.php), [lead visibility contract](brand-lead-visibility-contract.md), [shared types](../../src/resources/js/features/portals/types.ts).
- **Mockup:** [v2 Partner detail](../../mockup/v2/Partner.dc.html), [v2 Main](../../mockup/v2/Main.dc.html) and [export instructions](../../mockup/v2/README.md). Inspect rendered references as well as their source. Do not overwrite them or copy the exported runtime into production.
- Existing [BrandChrome](../../src/resources/js/features/portals/BrandChrome.vue), [PortalShell](../../src/resources/js/layouts/PortalShell.vue), [RegistrationTable](../../src/resources/js/features/brand-leads/RegistrationTable.vue), `PartnerAvatar`, `StatusBadge`, `ContentSection`, `DetailList`, `StatCard`, `Pagination` and shared controls.
- [Navigation/Home handoff](portal-navigation-home-handoff.md), [header alignment handoff](portal-header-alignment-handoff.md), [personal accent handoff](appearance-preference-handoff.md), [foundation tokens](../../src/resources/css/foundation.css), [portal styles](../../src/resources/css/portal-theme.css), and current appearance/shell CSS.
- **PDF:** [business plan](../../docs/Creator_Brand_Platform_Business_Plan.pdf) provides business context, not authorization to invent metrics or capabilities.

## Analyze the current page first

1. Read repository instructions and inspect the current diff. Preserve ongoing work, including local changes to `AccountMenu.vue` and staging deployment notes observed during handoff preparation; do not reset or overwrite them.
2. Open the target URL through the local app and inspect at 1440×900 and 390×900. Use an authorized local session and read-only navigation. Do not seed data, reset credentials, enroll/reset MFA, change appearance preferences, edit the shared profile, register leads or mutate the staging-derived local database for design inspection. If authentication blocks inspection, report that specific limitation and use current source plus synthetic mockup data; do not claim to have seen the live screen.
3. Record a concise critique tied to concrete observations: identity/context hierarchy, status/date placement, competing registration cards, information grouping, whitespace, table usability, mobile ordering and next-step clarity. Describe findings conditionally if live rendering is unavailable; do not infer visual defects from component names alone.
4. Inventory every existing field, permission, navigation target and state before designing. Explain how the recommended design preserves them and what it improves. Distinguish supported UI changes from optional future-product ideas.

## Current supported content and boundaries

Source inspection shows a partner identity/avatar and back link; partnership Active/Paused status; accepted date; last activity with kind, relative/exact time and explanatory scope; total currently shared leads; registrations in the last 30 days; optional shared business contact/name/email/website/description; and a paginated registered-lead table with working links to the existing registrations screen. Verify against the current payload before finalizing the inventory.

**Confirmed contract:** this screen shows what a creator/Partner shares with the selected Brand. It is not the Brand's own editable private CRM record. Brand membership does not confer access to the creator's private pipeline, reminders, notes, rates or contacts. Totals measure registrations currently shared with this Brand, not sales, revenue, all private leads or generic partner performance. The profile is controlled by the Partner. Preserve unavailable/paused behavior; do not reveal suppressed profile or registration details in the paused mockup state.

**Mockup interpretation:** v2's prominent record header, breadcrumb, grouped About information and related-record sections can inform layout. Its “Brand partner” label, deal-stage path, Follow, New contact, New deal, Edit buttons and field edit pencils do not establish capabilities for this Brand-facing creator record. Do not transpose private lead stages into partnership status. Do not create a fake activity feed or imply the Brand can edit the Partner's shared profile.

**Proposal:** favor an informative record header with status/context and compact meaningful registration summaries, followed by a clear primary registered-leads area and a complementary shared-profile/relationship area. Determine the final composition after inspecting the live screen. Avoid oversized generic KPI cards, repeated headings, empty decorative panels and filler activity lists. Every visible action should have a supported purpose or be explicitly identified as a future proposal outside the recommended baseline.

## Mockup deliverables

**Proposal destination:** create a standalone, locally viewable prototype at `mockup/brand-partner-detail/index.html`, with supporting assets only if needed. Put the design analysis and viewing instructions in `planning/shared/brand-partner-detail-mockup-review.md`. These are new files; preserve existing product/reference assets. Link the deliverables from this handoff and the planning/evidence indexes once they exist, labeling them **Mockup**, not implemented behavior.

The prototype must include:

- The current shared header/sidebar context, including the selected workspace name beside the avatar, existing disabled navigation entries and the current Brand light-header treatment. Reuse its visual language without redesigning navigation again. Keep the proposed detail content clearly situated within the real shell proportions.
- A populated detail layout using clearly synthetic Partner, workspace and registration data matching the existing data shape. Do not embed real local/staging contacts, session material, account credentials, UUIDs copied from real records or private data in committed mockup files. The target UUID above identifies the inspection route only.
- Responsive desktop/mobile presentation, meaningful reading order, readable labels, linked labels/controls where present, keyboard focus, 44px touch targets, and contained table overflow. Use semantic landmarks and one main page title.
- Both personal palettes: Burgundy `#8e1650` by default and Purple `#641d73` as an optional local preview setting. Preserve Manrope/Bricolage, cream/white/mint and semantic colors. Preview controls must be visibly separated from product content and must not persist to an account or call the app.
- Previewable populated, empty registrations, missing shared profile, paused/unavailable Partner and long-name/long-content states. They may use a small prototype-only state selector. Do not show stale hidden data in the paused state or use zero to mean unavailable.
- Prototype-only link/tab interactions where useful for judging the design, with no application API requests or mutations. Show how existing registration filtering/detail navigation and pagination would behave; mark demo navigation so it cannot accidentally target live records. A static UI must not claim to save/edit/send successfully.

The accompanying review should explain the main design decisions, map every current section to its proposed location, list reused production components and any justified future extraction, and identify unsupported/future ideas separately. Include the live-page inspection outcome, exact preview command/URL, artifact paths and remaining assumptions. Explain deviations from v2, especially the difference between relationship status and deal stages.

## Mockup verification and review gate

- Serve the prototype locally and inspect it in Chromium at 1440×900 and 390×900 in both palettes; check 320/768/1024px reflow and long content. Inspect the populated/empty/paused/missing-profile variants, keyboard focus, contrast, readable workspace identity, and no horizontal page overflow. Keep any intentional table scroll inside its container.
- Format new supported HTML/CSS/JS files with the repository's pinned Prettier and four-space configuration using explicit paths; verify formatting. Do not change production files merely to render the prototype. Run relevant lightweight prototype checks; production PHP suites/builds are not required for standalone mockup-only work. Report missing tooling or visual checks honestly.
- Keep screenshots temporary outside the repository and remove them after inspection. The requested HTML mockup and necessary design assets are deliverables; this does not request retained screenshot evidence. Record concise text findings, commands, viewport/browser and limitations in the review document.
- Validate new documentation links and `git diff --check`. Update planning links without marking the production redesign complete. Final response should provide the mockup/review paths, viewing command, rationale and unresolved choices, then stop for user review. **Approval of the mockup is required before a later production implementation stage; this requirement comes directly from the user's mockup-first request.**

## Handoff preparation verification

2026-11-25, `cce4871`: inspected `BrandPartner.vue`, the v2 Partner detail source and the sharing contract; current local application rendering was not inspected by the handoff author. Source findings are not visual acceptance. This handoff changes documentation only; Claude performs the requested live-page analysis and prototype next.
