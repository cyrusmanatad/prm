# Personal accent color — Claude handoff

Date: 2026-11-24. Inspected baseline: `2a161c2` on `feat/portal-navigation-home`. Status: implemented locally on 2026-11-24 (uncommitted, branch `feat/appearance-preference` from `feat/portal-navigation-home`); see [implementation evidence](appearance-preference-evidence.md). Not committed, merged or deployed.

## Confirmed direction

**Confirmed:** after Claude opened the navigation/Home PR, the user requested two user-selectable colors rather than replacing burgundy. Burgundy is the default; Purple is the alternative from v2. This is a personal preference, not workspace branding. The user requested this next before merging the navigation work; this handoff does not approve or merge that PR.

| Choice | Primary accent | Initial behavior |
| --- | --- | --- |
| Burgundy | `#8e1650` | Default for existing/new accounts and missing preferences |
| Purple | `#641d73` | Explicit user selection; v2 reference |

**Mockup:** [v2 Main](../../mockup/v2/Main.dc.html) and [Partner detail](../../mockup/v2/Partner.dc.html) establish the Purple design input. The [navigation/Home handoff](portal-navigation-home-handoff.md) remains authoritative for layout, routes, disabled features and Home behavior. This decision resolves its previously open color question by offering both accents.

**Proposal:** act as a senior SaaS UI/UX designer and frontend engineer, applying senior Laravel/PostgreSQL engineering judgment for preference persistence. Follow repository requirements and existing components; the role description does not replace the acceptance gates below.

## Preference behavior

- **Proposal:** store an allowlisted account preference (`burgundy` or `purple`) in PostgreSQL, with a Burgundy default. Persist across refresh, sign-out/sign-in, devices and workspace changes. Each user can update only their own preference; no user ID or workspace ID is accepted from the form. A browser-only/localStorage setting does not satisfy the personal-account contract.
- **Proposal:** add an accessible Appearance entry to `AccountMenu` and a small account Appearance page using the existing account layout conventions. Use a labeled radio group with named color swatches, selection indicated beyond color alone, and an explicit Save action. No confirmation modal is needed for this routine save. Report success and accessible validation/server errors; prevent duplicate submissions and preserve the saved choice on failure.
- **Proposal:** both options have the same layout, fonts, cream/white surfaces and mint accents. Change primary accent, hover/pressed states, soft accent backgrounds, relevant borders, links, active navigation, buttons and form accents consistently. Purple can use v2 soft accent `#ede1f2` and hover `#3d1052` as starting values, subject to contrast verification. Preserve semantic danger, warning, success, focus, stage and chart meanings.
- **Proposal:** apply the preference across the signed-in user's existing screens in Brand, Partner and Professional, including shared account/finance/detail screens and authorized administration surfaces where they use common accent tokens. Preserve Brand's current light-header treatment; personal color does not change shell structure. Do not tint immutable logo assets, uploaded content, other users' views, public/shared pages, transactional email or exports. Public/guest views use Burgundy.
- **Proposal:** use the server preference as the source of truth. Initial authenticated HTML must receive the selected appearance before first paint; maintain it through Inertia navigation and partial reloads. Apply the appearance attribute on the document root so teleported Reka menus, tooltips and dialogs receive the same tokens. No page-level theme wrappers that leave overlays Burgundy.
- **Proposal:** logout/account changes restore the appropriate server-selected theme, with Burgundy for guest/unknown values. Avoid unscoped browser caches that show the previous user's color on a shared device. Another tab/device can pick up changes on its next full request; immediate cross-device synchronization is outside this slice. Do not poll for appearance updates.

## Implementation starting points

- [PortalShell](../../src/resources/js/layouts/PortalShell.vue), [AccountMenu](../../src/resources/js/components/AccountMenu.vue), [FoundationShell](../../src/resources/js/layouts/FoundationShell.vue) and [AdminShell](../../src/resources/js/layouts/AdminShell.vue).
- [User](../../src/app/Models/User.php), [Inertia shared props](../../src/app/Http/Middleware/HandleInertiaRequests.php), [routes](../../src/routes/web.php), [initial Blade view](../../src/resources/views/app.blade.php), [client bootstrap](../../src/resources/js/app.ts).
- [foundation.css](../../src/resources/css/foundation.css), [portal-theme.css](../../src/resources/css/portal-theme.css), [portal-shell.css](../../src/resources/css/portal-shell.css) and [professional.css](../../src/resources/css/professional.css).
- [Navigation/Home implementation evidence](portal-navigation-home-evidence.md) records the delivered shell, route/menu inventory and existing gates. Reinspect the actual PR diff and latest commit; the evidence's older “uncommitted” description is historical and does not establish PR review approval.

## Implementation sequence

1. Inspect repository instructions, current PR/worktree and existing user-update patterns. Preserve the navigation/Home work, mockup assets, `.claude/` and the separate staging-secret-rotation plan. Do not overwrite Claude's evidence or treat this task as permission to merge/deploy.
2. Add a minimal additive user-preference migration with a default and database-level allowed-value constraint, following existing PostgreSQL/SQLite test compatibility conventions. Do not introduce a parallel settings service/table unless the existing architecture warrants it. Confirm rollback behavior on a disposable database.
3. Add an authenticated, CSRF-protected endpoint that derives the target from the signed-in account, validates the two supported values and updates only the appearance field. Reuse existing account lifecycle/security guards; appearance is not a reason to bypass MFA, suspension or closure restrictions. Use existing privilege patterns without broadening runtime access to other users' rows. Keep unrelated profile data untouched. Check whether existing own-account privacy exports need to include this new profile field.
4. Share a normalized, typed preference through Inertia and set the same allowlisted value in initial HTML. Do not accept arbitrary CSS, colors or classes. Keep common preference handling outside individual hub components, with safe Burgundy fallback for absent/unknown values.
5. Consolidate accent tokens so both foundation CSS variables and Tailwind portal utilities resolve to the selected palette at runtime. Audit compiled `@theme` output, semantic primary/ring tokens, `bg-brand`/`text-brand`/`bg-blush`, opacity variants, scoped overrides and hardcoded burgundy/purple values; a root variable change alone may not reach them. Preserve the original Burgundy rendering and avoid unrelated color replacements.
6. Implement the Appearance page/menu entry with existing radio/field/button/feedback conventions. Save once, apply the server-confirmed value without reload flashes, and keep both color choices reachable on desktop/mobile. Do not add a third theme, dark mode or workspace theme controls.
7. Verify both choices against the navigation/Home implementation and existing workflows. Record concise text evidence in `planning/shared/appearance-preference-evidence.md`, then update this handoff and planning/evidence indexes. Follow the existing PR workflow; whether Claude extends the open PR or opens a dependent PR is an integration choice to state explicitly, not authorization to merge.

## Acceptance and checks

- Existing/new users get Burgundy without needing to save. Purple selection persists across reload, reauthentication and workspace changes. Switching back to Burgundy works. One account's choice never changes another's, including consecutive logins in the same browser. Invalid values are rejected by the endpoint and constrained in PostgreSQL; presentation falls back safely for missing/unknown inputs.
- Verify authenticated GET rendering uses the saved value before client hydration, and Inertia navigation/partial reloads preserve it. Check document-root tokens, teleported menus/dialogs, collapsed sidebar, mobile drawer, active links, primary buttons, input states, errors and disabled feature labels in both palettes. Public pages/email/reference assets retain their intended appearance.
- Run focused PHP endpoint/persistence/authorization tests, real PostgreSQL checks for migration constraints and applicable user-access rules, Pint, relevant frontend/component tests, pinned formatting/checks, `npm run typecheck` and `npm run build`. Run relevant existing portal/navigation/Professional browser tests and a focused appearance journey in desktop/mobile projects. Do not use broad mutation-capable suites against the main local database copied from staging.
- Inspect Brand, Partner, Professional and account Appearance at 390×900 and 1440×900 in both palettes; inspect navigation/control reflow at 320, 768 and 1024px. Check normal/hover/focus/selected/disabled contrast, keyboard radio/save use, success/error announcements and 200% zoom. Inspect at least one teleported account menu and modal/drawer per palette. Do not claim a theme verified from header color alone.
- Use disposable synthetic accounts/stacks and local mail capture. Do not copy staging R2 credentials or enable real sending. Take screenshots only temporarily outside the repository and remove them after inspection. Record commands/results, revision, viewport/browser, token/component reuse, intentional scope differences and any unverified gates. No live migration or deployment is authorized by this handoff.

## Planning verification

2026-11-24, `2a161c2`: inspected the shared portal shell, account menu/user model, shared Inertia data, route structure and existing palette sources. This handoff adds documentation only. Application checks and palette rendering acceptance remain future implementation gates; relative links and documentation diff checks are validated for handoff delivery.
