# M3A Verification Evidence

Status: **automated development gates passed; manual release checks remain pending**. [Structured verification](verification.json) records scope, environment and results. See [M3A status](../../m3a-status.md) and the [contract](../../m3a-portal-contract.md).

## Reference inventory and visual comparison

The [reference snapshot](reference/README.md) preserves both supplied HTML files, adjacent CSS/demo JavaScript, local WOFF2 font files and OFL license. Demo JavaScript is not imported by the Vue application. The supplied supporting PNGs are also preserved in the snapshot; the comparison screenshots are browser renders of the authoritative HTML. The [SHA-256 inventory](reference-inventory.json), [fresh-setup source](fresh-setup-source-revision.json), [final source](final-source-revision.json) and [follow-up scope](follow-up-source-diff.json) make the reference and tested revisions reproducible.

[Rendering settings](render-settings.json), [layout metrics](visual-metrics.json) and [raw image differences](image-differences.json) document the comparison. Both Vue fixtures have **zero differing pixels** at 1440, 1024 and 390 widths with a matching 1000px viewport height, local fonts loaded, device scale 1, Chromium, en-US and Asia/Manila. No nonzero tolerance was used. Full-page reference/Vue, side-by-side and highlighted-difference PNGs are retained for every combination. Example: [partner desktop](side-by-side-partner-1440.png), [brand desktop](side-by-side-brand-1440.png), [partner mobile](side-by-side-partner-390.png), [brand mobile](side-by-side-brand-390.png).

The brand reference has a 757px document width at the 390px viewport. Its exact fixture preserves this source behavior. Real-account mobile views use bounded table/board scrolling and pass document-overflow checks. Fixture controls have no simulated business-success handlers; synthetic finance/health/performance content never appears on real-account routes.

Reproduce from the repository root and `src/`, in separate terminals:

```sh
python3 -m http.server 5502 --bind 127.0.0.1 --directory planning/evidence/m3a/reference
```

```sh
npx vite --config tests/Visual/vite.config.ts
node tests/Visual/capture.mjs
node tests/Visual/diff.cjs
```

## Automated verification

The isolated [fresh setup](clean-setup.json) passed in **1,013 seconds**, using new synthetic credentials/database/storage and the pinned official Playwright image from M3. It removed its own stack and volumes. No host application dependencies or local credentials were copied; build/tool caches could be reused. This was local execution, not remote CI.

| Check | Result |
|---|---|
| Identity/auth | 26 tests, 252 assertions |
| Real PostgreSQL regression | 26 tests, 475 assertions |
| Role/concurrency/process acceptance | 24 tests, 566 assertions |
| Frontend | Types, 21 tests and production build passed |
| Full browser suite | 58 passed; two existing duplicate mobile session tests intentionally skipped |
| Final portal follow-up | 12 passed across Chromium/WebKit desktop/mobile; guest invitation retention, durable registration, keyboard focus, privacy, termination/replay, overflow and axe checks |
| Final adversarial follow-up | PostgreSQL: 4 tests/77 assertions; partnership acceptance: 3 tests/59 assertions |
| Accessibility | 76 retained legacy scans: zero violations/incomplete findings; 20 final portal scans: zero violations |
| Visual parity | Six full-page comparisons: zero differing pixels |
| Other checks | Full Pint, standalone DDL, secret scan/positive control, strict Composer validation, npm audit and Composer audit passed |

The raw [fresh browser report](fresh-browser-results.json), [final portal report](final-portal-browser-results.json), [accessibility summary](accessibility-summary.json), backend/migration logs and real-account screenshots are retained here. Example final real-account screenshots: [partner mobile](real-portal-partner-mobile.png), [brand mobile](real-portal-brand-mobile.png), [partner desktop](real-portal-partner-desktop.png), [brand desktop](real-portal-brand-desktop.png).

`check-clean-setup.py --verify-portal-rollback` rolled back/reapplied both M3A migrations with synthetic creator data present, proving creator backfill, retained Repeat Client/Lost records/notes/values and no inferred partnerships. Restricted-runtime tests cover denied direct INSERT/UPDATE/DELETE, cross-brand rows, creator-private details, wrong recipient/context, consent, expiry, replay, duplicate acceptance, real PostgreSQL lock waits, owner/admin permissions, membership revocation, partial props and worker scoping. Existing M2/M3 regression journeys and file/capability isolation also pass.

The fresh setup tested the first complete implementation snapshot. The explicitly recorded follow-up covers the pinned **Vite 8.3.5 security patch**, production-build fixture guard, complete real-account roster columns, guest/keyboard browser assertions and extra denied-insert/removal checks. The final source fingerprint differs accordingly; earlier evidence is not presented as a test of later changes. An early host-bind test run had filesystem ownership failures; corrected runs used the container's storage. Composer's initial bridge-network advisory request timed out; the host-network retry passed. No product failure or unreachable audit is counted as a pass.

Manual screen-reader, actual full-browser zoom and native macOS/iOS Safari remain release gates. Automated large-text/root-font checks and Linux WebKit do not replace them. Production MFA, infrastructure/storage/operations decisions and later finance/automation/PRM milestones remain separate gates.

## Partner navigation and lead management follow-up

D24 has separate [verification and screenshots](partner-navigation/README.md): canonical partner URLs, legacy redirects, modal/drawer lead management and avatar navigation. All 40 final browser journeys pass across Chromium/WebKit desktop/mobile. Original parity evidence above remains scoped to its recorded source.

## Brand avatar follow-up

The [brand account dropdown](brand-avatar/README.md) adds the avatar beside Invite partners and moves workspace/security and sign out out of the footer. Its focused desktop/mobile Chromium/WebKit checks and screenshots are recorded separately from earlier parity and lead-consolidation evidence.
