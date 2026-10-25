# Pipeline correctness and mobile interaction repair — 2026-10-25

## Scope and behavior

**Confirmed:** the user requested proceeding with the next task after checkpoint `1646135`. This implements UI-02 and the mobile capture/save, fixture, shared-token and stale-test portions of UI-05. M5/M6 and unrelated CRM/notification work retain their existing scope. No schema, stored stages, permission model or external delivery behavior changes.

- Search is debounced against the existing server listing; stage, Repeat Client/Lost segments and due filters query the full workspace. Filter changes reset cursors, returned props restore controls and the header search, and browser history cancels an unsubmitted search. Pagination, opening/closing drawers and browser back/forward preserve the URL context. Mutation redirects retain validated `pipeline_filters` (`q`, `stage`, `due`) and restart at page one; invalid context is rejected before writing.
- The default **All leads** segment exposes all seven stored stages. The five primary columns remain Lead, Pitched, Negotiating, Onboarded and Active. Repeat Client and Lost use their own labelled sections and tags instead of being presented as Active.
- Column counts come from all matching server rows. Empty columns distinguish rows on other pages from zero matches. Headline totals retain filtered, all-page, non-lost, separate-currency semantics. Column amounts are explicitly labelled **Page value** and use exact integer arithmetic. The UI no longer claims an always-unfiltered headline.
- Shared SelectField replaces the board's competing dropdown. Shared Button and labelled ActionGroup supply filters/actions; PipelineColumn shares card, count and placeholder presentation across stages. Stage dots, surfaces, borders and feedback use the existing design tokens, fonts and focus treatments. Five primary columns fit the desktop partner composition; smaller viewports scroll the board within its own region.
- Mobile pointer failures were reproduced with the normal browser controls. A wide scroll area could enlarge Chromium's layout viewport (980px versus the 390px device viewport), so clicks hit unrelated cards/controls. `contain: inline-size layout paint` keeps the board and portal table overflow within their scrollports; mobile dialog bounds/anchors use the device viewport. A [diagnostic normal Next-page click](mobile-viewport-diagnostic.txt) fails before containment and succeeds after it. No forced clicks, disabled Axe rules or root-level overflow hiding are used.
- The visual fixture now renders the real production PipelineBoard with six synthetic leads; the former fixture-only board is removed. Public capture checks target the actual card button. The pipeline deletion journey positively checks the card before deletion and verifies its removal; seven-stage selection uses the visible dropdown.

## Verification

- Pinned changed-file formatting: **pass**, 15 supported frontend files. Explicit pinned formatting of the capture script: **pass**.
- Type checking, **45 component tests in 10 files**, and [production build](build.txt): **pass**. Regressions cover all-page counts, separate stages/currencies, exact large money, server filtering/cursor reset, pending responses and browser-history cancellation.
- PostgreSQL pipeline integration: **7 tests / 132 assertions**, [log](integration.txt). Includes existing cross-workspace IDs, scoped cursors, partial props, exact all-page aggregates and denied forged writes, plus new filter-context redirect/rejection checks.
- PostgreSQL role/revocation acceptance: **1 test / 29 assertions**, [log](roles.txt), covering owner/admin/manager/member/viewer, revoked membership and direct RLS denial.
- PHP unit/feature regression: **29 tests / 256 assertions**, [log](php.txt); changed PHP [Pint checks](pint.txt) pass.
- Final desktop/mobile browser run: **22 passed**, 11 per viewport, in 6.0 minutes; [log](browser.txt). The 31-lead regression covers mixed stages/currencies, older-record search, combined stage/due filters, URL/back/forward/drawer history, filter-preserving saves, other-page placeholders and empty/clear states. Seven-stage saves, stale tabs, viewer/revocation, duplicate-confirmation/password errors, both portal shells/relationships, onboarding/public capture and five-width responsive/keyboard journeys also pass with unchanged Axe rules.
- The unchanged Gitleaks scanner and its synthetic positive control [pass](secrets.txt): 688 reviewable files, no findings. The previous review's checksum file now stores explicit path/digest records, preserving all 51 historical values: two AuthScreen filename/value pairs had triggered the generic-key heuristic. No scanner exclusion or detection-rule change is added. [Reviewed source hashes](source-sha256.json), whitespace and planning-link checks identify the final handoff. Historical acceptance and exploratory runs do not certify the final bundle.

The served and local frontend manifests both hash to `d20a64312534a1cb8ba419b9eb08daf078ba61bcd3b354f7f1d39256032cce2c`. The final optional-filter normalization preserves a literal `q=0` while dropping `due=0`; the PostgreSQL integration/Pint checks were rerun after this backend correction.

## Visual evidence and reference reconciliation

The source reference remains [D22/D24 portal guidance](../../portal-ui-alignment.md) and the retained [partner](../m3a/reference-partner-my-leads-1440.png)/[brand](../m3a/reference-brand-partner-hub-1440.png) compositions. The newer [pipeline mockup](../../../mockup/pipeline-claude/pipeline-spec.md) supplies presentation input; its alternate font/palette, always-unfiltered totals and repeat-client interpretation do not replace the approved brand or stored data contract. The repair retains existing fonts/palette, five main columns and separate-currency semantics. Filters, honest automation state, page-value labels and supplemental stored-stage sections are deliberate functional additions to the original static reference, not a claim of exact image parity.

| Fixture | Desktop | Tablet | Mobile |
|---|---|---|---|
| Real production partner board | [1440px](fixture-partner-1440.png) | [1024px](fixture-partner-1024.png) | [390px](fixture-partner-390.png) |
| Brand table/composition | [1440px](fixture-brand-1440.png) | [1024px](fixture-brand-1024.png) | [390px](fixture-brand-390.png) |

[Capture script](capture.mjs) asserts the production partner board renders six cards/five primary columns and both documents fit each viewport. Inspected real-app captures include [desktop drawer](lead-drawer-desktop.png), [mobile drawer](lead-drawer-mobile.png), [paged desktop board](pipeline-pages-desktop.png), [paged mobile board](pipeline-pages-mobile.png) and [390px Add lead](add-lead-390-desktop.png). Full-page modal screenshots include page content beyond the viewport; the backdrop covers the viewport.

[Metrics](fixture-metrics.json) record Chromium, locale/timezone, fonts-ready capture, sizes and page errors. These are test-only synthetic reference pages; production automation and financial states remain unchanged. Broader explicit reference/deviation acceptance remains under UI-05, alongside inherited manual release reviews; this evidence does not silently certify exact portal parity.

Commands from `src/`:

```sh
npm run format:changed
npm run format:check:changed
npm run typecheck
npm test
npm run build
COMPOSE_PROJECT_NAME=weavdash npm run test:browser -- pipeline-filtering.spec.ts pipeline.spec.ts ui-refinement.spec.ts partner-navigation.spec.ts onboarding.spec.ts portals.spec.ts confirmation-errors.spec.ts --project=desktop --project=mobile --output=/tmp/weavdash-pipeline-final-browser --reporter=list
```

From the root: `docker compose --env-file src/.env -p weavdash build app acceptance`; start the rebuilt app with `up -d --no-deps --wait app`. The final local build was copied to the running app with `docker cp src/public/build/. weavdash-app-1:/var/www/html/public/build/`. PostgreSQL integration uses `exec -T app vendor/bin/phpunit -c phpunit.pgsql.xml --filter PipelineTest`; role acceptance uses `run --rm -T --no-deps acceptance vendor/bin/phpunit -c phpunit.acceptance.xml --filter PipelineRoleTest`. Unit/feature uses `exec -T app php artisan test --compact`; Pint checks the changed controller and integration test. Visual fixtures use `npx vite --config tests/Visual/vite.config.ts` and `node planning/evidence/pipeline-repair-2026-10-25/capture.mjs`.

Sandbox process/Docker restrictions required approved execution outside the sandbox. All fixtures are synthetic local records. Native Safari, Linux WebKit, actual screen-reader/voice-control, browser zoom, full backend acceptance, final-candidate remote CI and provider receipt remain unverified by this repair. No push, production deployment or real-recipient email is performed.
