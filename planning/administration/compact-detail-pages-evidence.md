# Compact administration detail pages evidence: the remaining eight

**Scope (2026-11-29):** the remaining administration detail pages, following the layout piloted on Account and Workspace ([pilot evidence](compact-detail-pilot-evidence.md)): Plan version, Record or replace assignment, Notification, Brand request, Audit event, Support access grant, My admin security (verification) and Administration unavailable. Branch `ui/admin-detail-pages` from `origin/main` at `7cd13d0`. Tested on the local Compose stack (`weavdash`), in Chromium only. Frontend only: no route, controller, SQL or data-contract change. Every administration page now uses the compact layout.

## Change

- **New `features/admin/AdminCard.vue`:** a compact titled card with an optional count, badge slot and note. It replaces `ContentSection` on these pages. `DetailList` is replaced by `FactList`.
- **Headers:** `AdminShell compact` with a fact line and copyable ID where the record has one (plan, notification, Brand request, audit event, support reference). Status badges sit on the right.
- **Plan version:** facts, a Create limits table and the Assignments table (compact mode, 44px row titles), each with a count.
- **Assign:** a Current state card (badge, plan facts, usage table) and the New assignment form in a compact card. The fields, warnings, review and confirmation are unchanged.
- **Notification:** the title now uses the readable kind label, as the list does. The resolution and uncertainty messages are page banners. Facts sit in a Delivery state card, and the resolution form is a compact card.
- **Brand request:** Applicant, Business information, Request, Decision (form unchanged) and History cards.
- **Audit event:** one fact card; Reason spans the full width.
- **Support access grant:** a Request fact card. "End support access" / "Withdraw request" is now an action row that opens `FormDialog` for the reason; its "Review ending" / "Review withdrawal" button leads to the unchanged Cancel-first confirmation. Records in scope keeps its area tabs, a compact table and numbered pages.
- **My admin security:** the verification or enrolment form and the recovery note in compact cards; the QR, setup key and fields are unchanged.
- **Administration unavailable:** shown outside the console, so it imports the records stylesheet and uses the same compact card.

## Verification

| Command | Result |
| --- | --- |
| `format:changed`, `typecheck`, `build` | Passed. |
| `npm test` | 38 files, 230 tests passed. The `buttons` test helper now ignores controls inside a closed dialog. The support grant test checks the dialog and its Review ending button before the confirmation. |
| Browser `administration`, `brand-approval`, `delivery-operations`, `professional`, desktop and mobile, shared stack | 39 passed, 1 skipped (planned). The support journey ends access through the new dialog and confirmation. |
| 44px sweep (temporary spec, deleted) | All eight pages, plus the withdraw dialog, at 1440 and 390 px. The first run found the Plan page's assignment workspace link at 36px; the table now uses its compact mode (44px). After that, no horizontal scroll and no target below 44px on these pages. The only remaining finding is the global success toast's close button (14×16), which predates this change and appears on every page that shows a toast. |

## Visual inspection

Temporary captures, outside the repository and not retained, of all eight pages at 1440 and 390 px. Cards, fact grids, forms and banners match the approved pilot layout; phones show labels beside values and full-width actions. The readable notification title was added after the captures and is covered by type checking only.

## Unverified gates

- The global toast close button below 44px (outside this change; listed for the smaller-items pass).
- WebKit and native Safari, manual screen reader and zoom reviews (inherited).
- Staging and production: not deployed.
