# Administration small fixes evidence: toast close target, own-row note, task register

**Scope (2026-11-23):** the smaller items left after the compact administration work. Branch `ui/admin-small-fixes` from `origin/main` at `1ac9be7`. Tested on the local Compose stack (`weavdash`), in Chromium only.

## Change

- **Toast close button (all pages):** the toast library draws its close button as a bare 14×16 icon. `src/resources/css/toasts.css` gives it a 44×44 target, pulled into the toast's own padding with negative margins so toasts keep their size. The visible icon, colours and focus outline are unchanged. Found by the 44px sweep in [compact detail pages](compact-detail-pages-evidence.md).
- **Platform access, your own row:** "This is you" no longer follows your email on the truncated line, where it was cut off at desktop widths. The note under it now reads "This is you. You cannot change your own grant." (plus the last-owner sentence where it applies). The email keeps a tooltip.
- **Task register (`planning/shared/remaining-tasks.md`):** ADM-01 to ADM-07 gain a dated note linking the compact console evidence and stating what is on staging (`a5782cc`, through PR #85; the detail pages from PRs #86–#87 are not yet deployed). The statuses stay "Verification pending" and "In progress": the administration package's manual acceptance has not been done.

## Verification

| Command | Result |
| --- | --- |
| `format:changed`, `typecheck`, `build`, `npm test` | Passed; 38 files, 230 tests. The own-row test now checks the full "This is you. You cannot change your own grant…" note. |
| Temporary check (deleted): a real success toast and Platform access at 1440 and 390 px | Toast close button 44×44 at both widths; no target below 44px on either page; the toast keeps its size; "This is you." shows in full. |
| Browser `administration`, `notifications`, `onboarding-notifications`, `flash-feedback`, `outreach-templates`, desktop and mobile | 39 passed, 1 skipped (planned). The toast specs, including their dismiss helper, pass with the larger close target. |
| Register links, `git diff --check` | All administration evidence links resolve; no whitespace errors. |

## Unverified gates

- WebKit and native Safari toast rendering.
- Staging: not deployed.
