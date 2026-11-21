# Staging bar removal evidence

**Scope (2026-11-21):** remove the site-wide staging bar ("Staging · Synthetic data only…") that appeared above every page's navigation when staging mode was on. **Confirmed (2026-11-21):** the user asked for it to go, because it isn't needed as an indicator. Branch `ui/staging-banner` from `origin/main` at `2f7417c`.

## Change

- **Removed:** the `StagingBanner` component, its mount in `app.ts`, the `.wd-staging-banner` styles and the now-unused shared `stagingMode` Inertia prop.
- **Kept:** `config('staging.enabled')` still controls staging behaviour. The upload-specific notices stay on the pages where files are handled. On the onboarding form and review pages, "Staging uploads are not scanned for malware" and the **Unscanned (staging only)** labels still come from `unscannedUploadsAllowed`. "Attachments are disabled" also still shows on the onboarding form.
- **`scripts/verify-staging.mjs`:** now asserts that the bar is absent instead of visible.
- **Out of date:** the staging deployment notes (`staging-deployment.md`, upload and inspection paragraphs) still mention the banner. That file has separate uncommitted work, so it wasn't edited here.

## Verification

| Command | Result |
| --- | --- |
| `format:check:changed`, `typecheck`, `build` | Passed |
| `npm test` | 38 files, 216 tests passed |
| `pint --test`; `phpunit` (pgsql) `--filter InertiaWorkspaceTest` | Passed; 4 tests, 103 assertions |
| `node --check scripts/verify-staging.mjs` and Prettier | Passed |

## Not verified

The live staging verifier and a staging deploy weren't run. The local stack runs without staging mode, so the bar never showed locally and local rendering is unchanged.
