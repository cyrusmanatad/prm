# UI update and planning review — 2026-10-25

## Disposition

**Review result: checkpoint the update; UI acceptance remains pending.** The user requested reviewing the UI updates, recording needed planning work and committing the changes. Reviewed `ui/audit-design-refinement` based on `2a5b7bb`, including the existing uncommitted UI, controller feedback, dependency, fixture and test changes. This review preserves that work and adds planning/evidence; it does not implement the outstanding repairs.

The [earlier refinement](../ui-refinement-2026-10-25/README.md) closes the scoped shared-select, dialog-traversal and accessible-name findings (UI-03/UI-04). Its frontend-only scope and “no new dependencies” statement describe that session, not the complete update: the complete diff includes pinned `vue3-toastify` 0.2.9, global flash/clipboard feedback, controller message changes and a subsequent CRM redesign. Earlier CRM screenshots do not certify this newer layout.

## Findings and plan changes

1. **P1 — Existing pipeline data correctness remains open (UI-02).** The board still filters the current server page, derives column counts/money from visible items and maps Repeat Client/Lost into Active. Search misses matching older rows; server filters, local controls and pagination can disagree. Preserve the seven stored stages, currency separation and D22 semantics. Implement and test server-backed filtering/history/cursors and truthful aggregates with more than 25 mixed-stage/currency leads before accepting the board. The [prior direct reproduction](../ui-review-2026-10-24/README.md) remains applicable to the unchanged logic; the passing single-page journey does not cover these cases.

2. **P2 — CRM reminder segments select a state without applying it (new UI-06).** `Crm.vue` changes `state` on segment clicks but only calls `list()` from Apply filters in the separate contacts card. Selecting Completed leaves pending rows visible and the URL unchanged. Reproduced at 1440px and 390px in [probe results](probe-results.json); see [desktop](crm-filter-1440.png) and [mobile](crm-filter-390.png). Query immediately from the segment or provide an adjacent, explicit applied/pending filter flow. Reset cursors and synchronize URL, returned records and pressed state. The updated CRM journey explicitly clicks the distant Apply filters button, so it does not establish that the segment works independently.

3. **P2 — CRM duplicates shared presentation and overstates counts/privacy (UI-06).** The new page introduces its own `crm-btn`, card, action and field styles instead of using existing shared Button/ContentSection/ActionGroup responsibilities. It retains the brand palette, fonts and responsive layout, but the duplicated system needs consolidation under the repository UI rule. “N saved” and overdue/today badges count only the returned 25-row page; label them as page-scoped or supply real aggregates. “Only you can see these notes” conflicts with workspace-private contacts readable by authorized workspace members. Correct the copy without changing permissions. Inspected the [desktop editor](crm-editor-1440.png) and [mobile editor](crm-editor-390.png): labels, focus styling and layout are retained; Escape restores New contact and neither probe viewport overflows. Preserve those behaviors, draft tabs, validation, viewer access and nested shared confirmations during consolidation.

4. **P2 — Invitation success copy claims delivery before it occurs (new UI-07).** `PortalController::invite()` and `OnboardingController::store()/review()` now flash “Invitation sent to…” / “Onboarding invitation sent to…” immediately after persisting queued intent. That does not prove transport success or recipient receipt; local array capture has no external delivery. Use queued/requested wording and let delivery history report actual state. Retain replacement/session-closure and one-time-link guidance. Verify global flash feedback across initial load, repeated actions/navigation, errors, dialogs, clipboard outcomes and reduced motion; retain actionable validation and stale-version recovery beyond transient toasts. This is UI correctness, separate from M5-09 provider acceptance.

5. **P2 — Production visual fixture and stale browser assertions still need acceptance (UI-05).** `tests/Visual/reference.ts` still selects the fixture-only old board. Render the real production PipelineBoard with equivalent synthetic data and compare both portal compositions, including paged/empty/error states and shared tokens. The current public-presence journey still expects an Inbound Studio link although the board renders a button. The pipeline deletion assertion similarly checks a nonexistent link and can pass without proving removal. Retain positive pre-deletion and negative post-deletion checks against the actual card. The current mobile pipeline run also times out clicking Save lead at `pipeline.spec.ts:25`: a drawer section intercepts pointer events. Preserve and investigate this separate failure; the trace identifies the blocked click but does not establish its root cause. See the [failure context](pipeline-save-mobile-failure.txt), [screenshot](pipeline-save-mobile-failure.png) and [trace excerpt](pipeline-save-trace-excerpt.json). Do not weaken Axe assertions or treat historical reference screenshots as acceptance.

**P1 — Mobile lead capture/save acceptance fails (UI-05).** The mobile responsive journey also times out clicking Capture lead after filling Brand name: the notes textarea intercepts pointer events. See [failure details](capture-mobile-failure.txt) and [capture screenshot](capture-mobile-failure.png). Investigate native touch/visual-viewport/dialog geometry and retain ordinary pointer assertions; do not force clicks to conceal the obstruction. This and the mobile Save lead timeout block current mobile pipeline acceptance. The screenshots/trace do not alone prove a root cause.

**Proposal / next task:** repair pipeline correctness and mobile capture/save under UI-02/UI-05 first, then finish UI-05–UI-07 and rerun affected acceptance. Resume M5-02 onboarding lifecycle emails after these UI repairs. M5/M6, production delivery and inherited manual release gates remain open. No business scope or stored stage lifecycle is changed by this review.

## Verification

The local app uses Compose project `weavdash`, not the older `weavdash-m1` handoff. The rebuilt local and served frontend manifests match at `b83976d1d0d57b6218ce1716276305aa3875eda15512798ea4862878af48ad21`. The container's Vue source copy predates the latest CRM edit, but the served compiled bundle matches the local build; the probe confirms the new CRM layout is served. Onboarding/portal controller checksums also match the reviewed source.

- Pinned changed-file formatting: **pass**, 37 files; types: **pass**; component tests: **42 passed in 10 files**; [production build](build.txt): **pass**.
- Existing backend unit/feature suite: **29 passed / 256 assertions**, [log](php-tests.txt). This is not PostgreSQL RLS/integration acceptance.
- Changed PHP formatting: **pass**, 13 files, [Pint log](pint.txt).
- Current desktop/mobile browser run: **8 passed / 4 failed** in 8.5 minutes, [full log](browser.txt). CRM, onboarding review/revocation and viewer/revocation pass on both viewports; the full pipeline journey and five-width responsive journey pass on desktop. The public-presence test fails on both viewports at the stale Inbound Studio link assertion. Mobile pipeline Save lead and responsive Capture lead time out due to pointer interception. Subsequent steps in failed journeys remain unverified; no assertions were weakened.
- [Direct CRM probe](probe.mjs): completed at 1440px and 390px; confirms the filter defect, Escape/trigger restoration and no document overflow. These are observed reproduction results, not passing regression tests for the defect.
- Gitleaks source scan and its synthetic positive control: **pass**, 645 reviewable files, no findings. `git diff --check` and explicit pinned Prettier for the probe: **pass**.
- [Reviewed source checksums](source-sha256.json) identify changed application/test files, including the dependency manifests. Prior refinement evidence remains historical evidence for its recorded sources.

Commands from `src/`:

```sh
npm run format:check:changed
npm run typecheck
npm test
npm run build
COMPOSE_PROJECT_NAME=weavdash npm run test:browser -- ui-refinement.spec.ts crm.spec.ts pipeline.spec.ts onboarding.spec.ts --project=desktop --project=mobile --output=/tmp/weavdash-ui-review-20261025 --reporter=list
```

From the repository root: `node planning/evidence/ui-plan-review-2026-10-25/probe.mjs`, `docker compose --env-file src/.env -p weavdash exec -T app php artisan test --compact`, and Pint `--test` on the changed controllers/demo script. Sandbox process/Docker restrictions required approved execution outside the sandbox. All accounts/records are synthetic local fixtures. No production deployment, push or real-recipient email was performed. Full PostgreSQL acceptance, native Safari/WebKit, actual assistive technology, browser zoom, final-candidate remote CI and provider receipt are unverified by this review.
