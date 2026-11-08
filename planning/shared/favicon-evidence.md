# Logo favicon verification

Date: 2026-11-08. **Confirmed:** use the existing `BrandLogo.vue` artwork directly, preserving the burgundy, cream, gold and mint colors, with a transparent square canvas and balanced padding. The on-page component is unchanged.

## Delivered

- [SVG favicon](../../src/public/favicon.svg): original paths/circles, square `viewBox="-6 -12 144 144"`, transparent outer edges.
- [ICO favicon](../../src/public/favicon.ico): 16×16, 32×32 and 48×48 32-bit frames, rendered from the SVG with Chromium. Includes legacy transparency masks.
- [Shared document head](../../src/resources/views/app.blade.php): explicit ICO fallback and preferred SVG links, versioned with `?v=20261108`. Bump the version when replacing these assets in the future.

No dependencies were added. Existing unrelated working-tree changes are preserved.

## Verification

Run from `src/` unless specified:

| Command | Result |
| --- | --- |
| `npm run format:check:changed` | Passed: 39 existing modified/new frontend files. |
| `npm run format:check -- public/favicon.svg --parser html` | Passed; SVG requires an explicit Prettier parser. |
| `npm run format:check -- ../planning/evidence/favicon/check.mjs ../planning/evidence/favicon/results.json --config .prettierrc.json` | Passed. |
| `npm run typecheck` | Passed. |
| `npm run build` | Passed: 3,236 modules. |
| `npm test` | Passed: 16 files, 68 tests. |
| `COMPOSE_PROJECT_NAME=weavdash-professional npx playwright test portals.spec.ts --grep 'portal logins share identity' --project=desktop --project=mobile --output=/tmp/weavdash-favicon-browser --reporter=line` | Passed: both desktop/mobile portal sign-in journeys. Synthetic fixture identities only. Temporary output avoids existing reports owned by another user. |
| `node planning/evidence/favicon/check.mjs --generate` (repository root) | Passed; generates ICO frames and verifies exact original artwork, transparent pixel edges, HTTP bytes, browser decoding and Chromium favicon requests. See [machine-readable results](../evidence/favicon/results.json). Omit `--generate` to verify without rewriting the ICO. |

The running `weavdash-professional-app-1` mounts this checkout's `src/` at `/var/www/html` and publishes `127.0.0.1:8000`. Both versioned favicon URLs returned HTTP 200 with bytes identical to the source assets. Chromium requested the SVG favicon during normal document navigation; the ICO also decoded successfully. No container restart, recreation, database operation or volume change was required for asset serving.

[Native tab-size comparisons](../evidence/favicon/tab-sizes.png) were visually inspected on light and dark backgrounds at 16, 32 and 48 pixels, alongside the SVG. Padding prevents clipping; all four colors are retained. [Desktop](../evidence/favicon/login-desktop.png) and [mobile](../evidence/favicon/login-mobile.png) login captures confirm the on-page logo remains in place. Existing adjacent sign-in/recovery links in those captures are outside this favicon change. Browser tab selection is verified through Chromium's favicon network request; the screenshots show page content and native-size icons, not browser chrome. Native Safari and manual browser-chrome appearance were not checked.
