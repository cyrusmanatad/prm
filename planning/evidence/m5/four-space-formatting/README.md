# Four-Space Prettier Follow-up

Status: **formatting/type/component/build checks passed; broad browser run has an existing accessibility finding** on 2026-10-23.

The user requested four-space Prettier indentation, including Vue. `.editorconfig` and the base Prettier configuration already specified four spaces; a Vue-only override specified two. The override was removed, all 28 Vue files were formatted with pinned Prettier 3.9.7, and `AGENTS.md`/the root README now describe the same four-space rule. Standard Prettier wrapping also expands older compact markup. Dependencies and formatting enforcement remain unchanged.

## Verification

Run npm commands from `src/`:

- `npm run format -- 'resources/js/**/*.vue' .prettierrc.json`: all 28 Vue files and the configuration formatted.
- `npm run format:check:changed`: 29 supported changed files passed.
- Effective Prettier configuration was resolved with EditorConfig enabled for every Vue file: all 28 use `tabWidth: 4` and `useTabs: false`.
- `npm run typecheck`: passed.
- `npm test`: 21 tests across five files passed.
- `npm run build`: passed.
- `docker compose --env-file src/.env build app`, then `up -d --no-deps app` from the repository root: rebuilt app used for browser verification.

Run the broad Chromium suite from `src/`:

```sh
COMPOSE_PROJECT_NAME=weavdash npx playwright test --project=desktop --project=mobile
```

Result: **35 passed, 2 failed, 1 intentional mobile duplicate skipped**, in 5.6 minutes; see the [complete log](browser.txt). Both failures are the existing workspace/security accessibility test's WCAG 2.2 `target-size` assertion on the Open partner portal link (`a[href$="leads"]`), on desktop and mobile. The assertions were preserved. The finding is retained as **M1-06** in the [remaining-task register](../../../remaining-tasks.md); it is not claimed resolved by formatting.

For the affected workspace template, the baseline from `60c580a` and formatted source were parsed/compiled with installed `@vue/compiler-sfc`, using the same filename and scope ID. Generated template code was identical. This establishes that the failing template's rendered output is unchanged by the formatter. See [finding details](accessibility-findings.json), [desktop failure screenshot](workspace-target-size-desktop.png) and [mobile failure screenshot](workspace-target-size-mobile.png).

The app was rebuilt from the formatted sources. The local scheduler was paused for deterministic pending-email assertions and restored after the run; mail uses local capture. No provider email or production deployment occurred. Existing milestone implementation/release gates remain open as recorded in the task register, including this additional observed accessibility gap.

## Visual review

Inspected desktop/mobile finance and brand portal screenshots retain the established cards, fields, layout and responsive wrapping. Shared components are reused throughout; the change applies standard Prettier source formatting. Evidence:

- [Desktop finance](draft-desktop.png), [mobile finance](draft-mobile.png)
- [Desktop brand portal](portal-brand-desktop.png), [mobile brand portal](portal-brand-mobile.png)

Formatting/type/component/build gates passed. The broader browser gate remains partially failing under M1-06. Linux WebKit/native Safari and manual screen-reader/full zoom acceptance were not rerun for this follow-up. Source-secret and documentation-link/task-reference checks passed. Captured text logs have trailing whitespace normalized.
