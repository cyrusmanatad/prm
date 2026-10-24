# UI working-tree review — 2026-10-24

## Disposition and scope

**Review result: changes require correction before UI acceptance.** Reviewed branch `ui/audit-design-refinement`, HEAD `eac7de28c1dc87b8d7ef8ad80a31ca6876dc510c`, including its uncommitted and untracked UI files. This review preserves the implementation and reference files; it adds review evidence and planning updates. Findings below are unresolved, not completed fixes.

The diff changes shared navigation/account menus, select fields, modal styles/focus handling, pipeline presentation, CRM/templates, workspace settings, onboarding/public presence and finance. No controller, service, route, migration, policy, dependency manifest or lockfile changes are present. That bounds the review; it is not proof of every backend invariant. Existing browser journeys exercise the local PostgreSQL application, while full backend acceptance/integration suites were not rerun for this UI review.

## Findings

1. **P1 — Pipeline filters no longer query the complete workspace (UI-02).** `PipelineBoard.vue:97–113` filters only `props.entries`, which is one 25-row page. Search, segments, stage and due controls do not request updated rows; `nextPage()` uses the original server filters instead. With 27 records, an older matching lead is absent from board search but appears using the same server query. Changing a prefiltered query or switching from `?stage=lost` to All active also leaves the old server subset in place. Restore server-backed live filtering, reset cursors when filters change and synchronize URL, returned props and controls. Verify with matching records beyond page one, due/stage/segment combinations, pagination, drawer close and browser history.

2. **P2 — Stage counts and empty states misrepresent paginated data (UI-02).** `PipelineBoard.vue:127–155` derives column counts and money from visible page items. The probe has one Pitched lead, but page one says `Pitched / 0 / USD 0.00 / No leads`. The [pipeline contract](../../m2-pipeline-contract.md) requires counts across matching rows and an explicit other-pages placeholder. Preserve those counts; either provide correct aggregate stage values or clearly label page-only amounts. The header is also not globally unfiltered: `Pipeline::listing()` applies filters before calculating totals, so entering the same search through the header/URL changes the headline to one lead while local filtering leaves it at 27. Resolve this with the contract rather than claiming an always-unfiltered total.

3. **P2 — Existing stages are presented as Active (UI-02).** `PipelineBoard.vue:32–34` maps both `repeat-client` and `lost` to the Active column. Lost cards have no Lost tag, and Repeat Client amounts/counts are mixed into Active. Stored rows are unchanged, but the board contradicts the seven-stage editor and previously separate omitted-stage access. Preserve the five main columns and offer clearly labelled Repeat Client/Lost lists or another explicitly agreed representation; do not silently treat the mockup's repeat-client tag as a schema/lifecycle change.

4. **P2 — Keyboard selection can choose a different option than the focused one (UI-03).** `SelectField.vue:101–121` intercepts Enter anywhere in the panel and chooses `filtered[active]`; option buttons update `active` on mouseenter but not keyboard focus. Opening Workspace type, pressing Tab twice to focus Brand and pressing Enter leaves `creator` selected. Reproduced at 1440px and 390px. The same shared control is used for lead stages, activity types, reminder filters and payment/draft allocation. Use one consistent focus/selection model, ensure focused search exposes the active option to assistive technology, and test the visible control with keyboard and pointer input. Existing browser tests target hidden native selects, so their successful mutations do not verify the replacement interaction.

5. **P2 — Dialog focus traversal includes hidden controls (UI-03).** `useDialogFocus.ts:3–16` includes the Delete permanently button inside a closed disclosure and omits its focusable summary. Reverse Tab from Close lead attempts to focus the hidden button and prevents normal traversal. The probe reproduces focus remaining on Close instead of reaching the visible Delete lead disclosure at both viewport widths. Use actual tabbable controls, including summaries and excluding hidden/inert controls, or rely on the native dialog's correct traversal. Verify closed/open disclosures, disabled fieldsets, nested selects and confirmation focus restoration.

6. **P2 — Account menu and lead cards fail the accessible-name gate (UI-04).** `AccountMenu.vue:48–58` displays the account name/email but hides them from accessibility and labels the button only “Account menu”. The finance and notification browser accessibility scans report serious `label-content-name-mismatch` on `.wd-account-trigger`. Include the visible identity in its accessible name (and adapt tests without weakening accessibility assertions). Check all three consumers: foundation shell, partner chrome and brand dashboard. The relationship journey also reports the same rule on `.lead-card`: its `Open ${brand}` label omits visible amount/tag text. Preserve that visible content in the accessible name, or deliberately associate supplementary content as a description without hiding meaningful lead information.

7. **P2 — Visual fixture does not render the new production pipeline (UI-05).** `PartnerDashboard.vue:261–401` retains the old fixture-only board, whereas real accounts use the new `PipelineBoard` through `LeadManager`. The existing `tests/Visual/reference.ts` enables that fixture, so a reference screenshot can pass while missing the new board entirely. Supply synthetic data to the real component and capture the updated desktop/mobile composition, empty/paged/error states and visible dropdowns. Keep historical M3A reference evidence as historical evidence.

8. **P2 — Browser assertions are only partially migrated to the new markup (UI-05).** `onboarding.spec.ts:205` still looks for an Inbound Studio link even though the captured page contains an Open Inbound Studio button; the journey fails after successful capture/revocation. `pipeline.spec.ts:25` now resolves two status elements when a Lost move empties the board, so its strict locator aborts the journey. Its deletion check at line 91 also searches for a link that no longer exists even before deletion, making that assertion ineffective. Scope status checks to the drawer and migrate card assertions consistently, including a positive pre-deletion assertion. These are test defects distinct from the functional regressions and Axe failures above; they leave later workflow steps unverified.

## Alignment observations and planning implications

The current screenshots retain the burgundy/cream palette, Manrope/Bricolage typography, card treatment and responsive wrapping. `AccountMenu`, `PartnerChrome`, `DialogClose` and shared danger/warning styles improve reuse; media-kit editing now shares partner navigation. Destructive actions still call `confirmAction`, and explicit onboarding, consent, stale-version guards and disabled automation remain present in the reviewed diff.

Visual follow-up: the CRM screenshot shows adjacent Edit/Complete actions running together, and the templates editor directly touches the following draft card. Review action spacing and section gaps under UI-05 while preserving the new layouts. Passing formatting does not establish visual alignment.

**Mockup:** [pipeline reference](../../../mockup/pipeline-claude/pipeline-reference.dc.html) and [specification](../../../mockup/pipeline-claude/pipeline-spec.md) are newly supplied design inputs. They request different tokens/font and unfiltered headline totals. The implementation retains the application's existing fonts, introduces local hardcoded board colors and changes aggregate presentation. **Assumption / Open:** these design inputs do not by themselves approve changes to D22's stage/data semantics or replace the complete portal acceptance baseline. Record the intended reference scope and map board styling to shared tokens before closing UI-05. The duplicated account-security form in `WorkspaceScreen.vue` should also be consolidated while retaining both selected/unselected layouts.

**Proposal:** repair UI-02 through UI-05 before accepting this UI revision or resuming changes that depend on these controls. Preserve the approved M5-02 implementation sequence and all M5/M6/release work; this review does not expand business scope or close those gates.

## Verification

- Pinned changed-file [formatting](format.log): 47 supported frontend files pass.
- [Type checking](types.log): passes.
- [Component tests](tests.log): 8 files / 32 tests pass.
- [Production build](build.log) and local app image rebuild: pass; rebuilt app is healthy at `http://localhost:8000`, Compose project `weavdash-m1`.
- [Desktop/mobile browser suite](browser.log): **25 passed, 16 failed, one intentional mobile duplicate skipped** (42 scheduled; 12.7 minutes). [Failure classification](browser-failures.json): 12 accessible-name failures (UI-04), two stale inbound-lead link assertions and two ambiguous pipeline status assertions (UI-05). These failed journeys stop early; their remaining steps are unverified. No assertions were weakened.
- [Direct reproduction results](probe-results.json) and [reproduction script](probe.mjs): synthetic local account, 27 leads, desktop 1440×1000 and mobile-width 390×1000. These are observed failure reproductions, not passing regression tests.
- [Source checksums](source-sha256.json) identify the 51 reviewed source/reference files; planning/evidence additions are outside that snapshot.
- `git diff --check`, explicit pinned formatting/syntax checks for the reproduction script and local documentation link validation pass. Source checksums confirm the reviewed application/reference files were preserved.

Commands from `src/`:

```sh
npm run format:check:changed
npm run typecheck
npm test
npm run build
COMPOSE_PROJECT_NAME=weavdash-m1 npm run test:browser -- adversarial.spec.ts foundation.spec.ts confirmation-errors.spec.ts accessibility.spec.ts portals.spec.ts crm.spec.ts pipeline.spec.ts partner-navigation.spec.ts finance.spec.ts onboarding.spec.ts onboarding-notifications.spec.ts notifications.spec.ts outreach-templates.spec.ts --project=desktop --project=mobile --output=/tmp/weavdash-ui-review-browser --reporter=list
```

Commands from the repository root:

```sh
docker compose --env-file src/.env -p weavdash-m1 build app
docker compose --env-file src/.env -p weavdash-m1 up -d --wait app
node planning/evidence/ui-review-2026-10-24/probe.mjs
git diff --check
```

The sandbox initially blocked process/Docker access; verification was rerun with the environment's execution approval. No production deployment or real-recipient email was performed. Native Safari, Linux WebKit, actual screen-reader/voice-control and browser zoom, current-candidate remote CI and full backend acceptance remain unverified by this review.

## Inspected screenshots

| View | Desktop | Mobile width |
|---|---|---|
| Real paginated pipeline, including incorrect Pitched empty state | [1440px](pipeline-page-1440.png) | [390px](pipeline-page-390.png) |
| Board search incorrectly misses an older record | [1440px](pipeline-missed-search-1440.png) | [390px](pipeline-missed-search-390.png) |
| Focused Brand option before Enter selects creator | [1440px](select-keyboard-1440.png) | [390px](select-keyboard-390.png) |
| CRM layout and adjacent action spacing | [Desktop](crm-desktop.png) | [Mobile](crm-mobile.png) |
| Template editor and draft-section spacing | [Desktop](templates-desktop.png) | [Mobile](templates-mobile.png) |
| Stale-record confirmation, blocked retry and preserved unsaved draft | [Desktop](confirmation-stale-desktop.png) | [Mobile](confirmation-stale-mobile.png) |
