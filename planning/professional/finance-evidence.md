# Finance evidence

**Scope (2026-11-21):** Private invoice drafts and Manual payment records, which Partner (`/finance/…`) and Professional (`/professional/finance/…`) share. This follows the user-approved Option A mockup: two pages, each a table with a side drawer and dialogs. Branch `ui/finance` from `origin/main` at `723bccd`. Tested on the local Compose stack (`weavdash`) with rebuilt `app`, `worker`, `scheduler` and `acceptance` images, in Chromium only.

## Change

- **Header:** `FinanceLayout` now has the compact 28px header with a one-line subtitle and an actions slot. The Pilot entitlements page uses the same layout. Partner keeps its finance navigation row.
- **Amounts:**
  - Every amount shows in its currency's format, such as `USD 3,700.00`. It is built from the exact minor-unit strings using the existing `money.ts` helpers, and the exact minor units appear as a tooltip or hint.
  - Inputs take currency units ("1,250.50"). They are converted exactly; amounts that are invalid for the currency are refused before sending.
  - Currencies are never added together; tiles show one line per currency.
- **Private invoice drafts:**
  - **Tiles:** open drafts, open total, received, and balance with a past-due count.
  - **Filters:** Open, Voided and All, kept in the address.
  - **Table:** each row shows the recipient, line count and version, then the due date (past due in red), the total, a received bar with the balance, the status and a row menu (Open, Edit, Void draft). Edit and Void are disabled while receipts are applied.
  - **Drawer:** a row, or the existing `/finance/invoices/{id}` address, opens a drawer over the list. It shows the line items with amounts and the total, the receipts applied (with reversed ones struck through and their reason), the balance, and Void draft, Edit draft and Match a receipt.
  - **Line editor dialog** (New draft and Edit): live line amounts, the exact total, up to 50 lines, "Unsaved changes", and Save disabled until something changes. Errors appear under fields, and messages that belong to no field, such as a reached quota, appear as an alert.
- **Manual payment records:**
  - **Tiles:** recorded, allocated, unallocated and voided.
  - **Filters:** All, Unallocated and Voided.
  - **Table:** the reference with each allocation's draft name (linked) or "Not matched yet", the received date, the amount, an allocated bar, the status, an **Allocate** button where it applies, and a row menu (Allocate, Reverse allocation to …, Void receipt, Record correction).
  - **Record receipt dialog:** also used for corrections, with the voided receipt and its amount pre-filled.
  - **Allocate dialog:** offers only open drafts in the same currency that still have a balance, and pre-fills the largest amount allowed.
  - **Reverse:** asks for the reason first, then the shared Cancel-first confirmation, which names the amount, receipt, draft and reason.
  - Void draft and Void receipt keep their confirmations.
- **Phones:** tiles 2×2, two-line records, a full-width drawer, line-editor cards and 44px targets.
- **Components:**
  - New: `InvoiceDrawer`, `InvoiceEditor`, `RecordReceiptDialog`, `AllocateDialog`, `ReverseDialog`, `useFinanceCommand` (one idempotency key per identical request) and `features/finance/amounts.ts`.
  - Reused: `DataTable`, `FilterChips`, `RowActionMenu`, `StatusBadge`, `EmptyState`, `SelectField`, `DialogClose`, `containDialogFocus` and `confirmAction`.
  - The Automation stylesheet became the shared `resources/css/records.css` (prefix `rec-`) used by both pages, instead of a second copy.

## Backend contract

- **`InvoiceDrafts`:**
  - `listing()` adds `received_minor` (unreversed allocations, as exact text) and `line_count`, and takes a `status` filter (`open`, `voided`, `all`; default `open`).
  - New `summary()`: per currency, open drafts, total, received and balance; plus open, voided and past-due counts.
  - New `allocatable()`: open drafts that still have a balance.
  - `get()` adds `received_minor` and `allocations` (reference, received date, amount and reversal reason).
- **`ManualPayments`:**
  - `listing()` takes `status` (`all`, `unallocated`, `voided`) and adds each allocation's `recipient_name`.
  - New `summary()`: recorded, allocated and unallocated per currency, plus counts.
- **Controllers:**
  - The invoices index returns the list, summary and filters, plus `invoice` at a draft's address.
  - The payments index adds `summary`, `openDrafts` and `filters`, and keeps `drafts`.
  - After a page form, both return to the list or draft it came from, keeping only a valid `status` (the new `Controller::returnTo()`, which `automationReturn()` now uses).
- All reads stay inside the owner-only tenant context. No migration, route or permission change.

## Verification

| Command | Result |
| --- | --- |
| `acceptance` over invoice, payment, isolation, entitlement, privacy export, governance, Professional Hub, schedule and follow-up suites | 63 tests, 1,312 assertions passed. The new `test_finance_pages_summarize_balances_per_currency_filter_and_return_to_the_same_view` checks exact 16-digit totals per currency, reversed allocations being excluded, past due, both filters (and refusing an unknown one), the allocation list, allocatable drafts and the redirects. |
| `pint --test`, `format:check:changed`, `typecheck`, `build` | Passed. |
| `npm test` | 38 files, 216 tests passed. The new `Finance.test.ts` covers exact conversions, tiles and rows, creating a draft (validation, exact minor units and the idempotency key), the locked drawer and closing it, allocation limits and the request, and the reason-then-confirm reversal. |
| Browser `finance`, `professional-ui`, `professional`, `ui-refinement`, `flash-feedback`, `automation`, `follow-up-drafts`, desktop and mobile | 22 of 24 passed on the broad run. The `finance` specs then passed 4 of 4, and `professional-ui` "Professional tools" passed 2 of 2 after the final fixes. |

The `finance` and `professional-ui` specs were rewritten for the dialogs and drawer. They keep the 16-digit exact-money checks, the stale-version void (the refused void now reloads the latest version and shows the reload alert), the confirmations, the quota alert and axe.

Component and browser tests found these problems, all fixed:

- The page didn't listen to the editor's save, so nothing was submitted.
- Focus didn't return to the row menu after a reversal; row menus were disabled while busy.
- The drawer's scrolling area couldn't be reached by keyboard (axe `scrollable-region-focusable`); it is now a labelled, focusable region in both drawers.

## Rendering

- **Inspected:** 1440 × 900, 1024 × 900 and 390 × 900 for Professional: drafts list, drawer, line editor, payments list and the allocation dialog.
- **Fixed:**
  - Phone records missing their layout (from the style rename).
  - Line amounts without thousands separators.
  - The due-date field overflowing the editor on phones.
  - Allocation lines being cut off on phones.
- No horizontal overflow; `ui-refinement` also checks 320, 768 and 1024 for the Partner pages. Temporary captures and specs were deleted.

## Not verified

WebKit/Safari, staging, a manual screen reader, and the full PostgreSQL suite with `rls.sql`. The last small changes (keyboard-focusable drawer region, row-menu focus) were checked by the browser specs, not by fresh screenshots.
