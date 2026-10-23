# M4 Financial Records and Entitlements Contract

Status: **implemented; verification recorded in [M4 status](m4-status.md); release acceptance pending** on `m4/financial-records-entitlements`. The user requested starting M4 in a new branch on 2026-10-23. D01/D13/D15 authorize local development of drafts and manual reconciliation. M3A remains the preceding development baseline; carried-forward manual release reviews remain open.

## Delivery slices

1. Private invoice drafts: exact money arithmetic, line items, due dates, owner authorization, forced PostgreSQL RLS, optimistic versions and idempotent commands.
2. Manual payment records and partial allocations: currency matching, allocation bounds, duplicate evidence detection and history-preserving corrections. No bank or payment-provider connection.
3. Explicit pilot plan versions and time-limited entitlements: audited local administration, transactional usage enforcement, concurrent quota checks and data-preserving downgrades. Pricing, an active-brand definition and numerical limits remain **Assumption / Open**; do not silently configure the PDF's commercial hypotheses.
4. Complete M4 acceptance: finance roles, cross-tenant attacks, command races, fresh setup, browser journeys and retained evidence.

## First slice: draft invoices

**Proposal / implementation default:** workspace owners alone can read or mutate private invoice drafts. Admin/manager/member/viewer have no financial access; partnership never confers access. Finance delegation and step-up controls require a later contract. Both workspace types may keep private drafts; recipient names are snapshots, not implicit sharing grants.

- A draft contains recipient business name, three-letter uppercase currency code, optional due date and 1–50 ordered lines. Each line has a description, integer quantity (1–1,000,000) and nonnegative minor-unit price as a canonical decimal string.
- Codes label money; this slice does not validate a currency registry, infer decimal places, convert currencies, calculate tax, discounts or fractional quantities. Enter prices directly in the currency's minor units. UI and JSON preserve exact decimal strings, including above JavaScript's safe integer range.
- PHP checks each product and the total against signed 64-bit maximum (`9223372036854775807`) before multiplication/addition. No floating-point calculation or implicit rounding occurs. Zero-value drafts are permitted.
- Every invoice is visibly **Draft — not issued**. There is no invoice-number sequence, issue/send/download-tax-invoice command or public sharing route. Accounting approval still gates actual issuance and numbering conventions.
- Updating replaces all lines atomically, increments `version` and requires the current version. Voiding is terminal, preserves the draft and lines, and records an audit event. There is no deletion command.
- Private tables are `invoice_drafts`, `invoice_draft_lines` and `financial_commands`. Composite foreign keys prevent cross-workspace line ownership. Forced RLS restricts every operation independently of HTTP authorization. Audit events contain identifiers/event names only.

## Session-protected web/JSON interface

All routes require authentication, verified email, selected active workspace and owner access. Inertia forms use redirects and validation error bags; JSON clients use `Accept: application/json`. Existing `/api/v1` remains a future external interface.

| Method/path | Contract |
|---|---|
| `GET /finance/invoices` | Inertia draft list or JSON `{workspace, invoices: {items, nextCursor}}`; optional UUID `cursor`, page size 25 |
| `GET /finance/invoices/{id}` | Inertia editor or JSON `{workspace, invoice}` including ordered lines; inaccessible IDs return 404 |
| `POST /finance/invoices` | Create `{recipient_name, currency, due_date?, lines}`; JSON 201 `{id, message}` |
| `PATCH /finance/invoices/{id}` | Replace draft fields/lines plus integer `version`; JSON 200 `{id, message}` |
| `POST /finance/invoices/{id}/void` | Terminal void with integer `version`; JSON 200 `{id, message}` |

Every mutation requires a UUID `Idempotency-Key` header or form `command_id`. Scope: workspace, actor and operation (including target ID). Replaying the same key and normalized payload returns the original result without another mutation or audit event. A changed payload for the same key returns 409. Keys are retained without expiry for this slice; retention/pruning is a later production decision. Per-command transaction advisory locks serialize concurrent retries. Failed transactions reserve no key. Authorization is checked before replay, so demotion/revocation cannot replay privileged results.

Line input: `{description: "Campaign assets", quantity: 2, unit_price_minor: "12500"}`. Returned line totals, unit prices and invoice totals are decimal strings. Invoice `status` is `draft` or `voided`; `version` is an integer. User-supplied organization, total, status and line IDs are never trusted.

Failures: 403 role/membership denial; 404 inaccessible record/cursor; 409 stale version, changed idempotency payload or terminal draft; 422 malformed input, missing command key or overflow. Browser conflicts include a reload path; never silently retry with a new version.

## Draft acceptance scope

Verified checks cover boundary money arithmetic, draft lifecycle, same-key retries/changed payloads, stale edits, pagination, all-role denial, revocation and direct PostgreSQL read/insert/update/delete attacks on all three new tables. Concurrent retry acceptance must observe a real PostgreSQL lock wait. Chromium browser checks cover desktop/mobile create/edit/void, stale-tab reload, precision-preserving input/output and axe accessibility. Draft checks alone do not establish payment allocation, issuance, subscription charging or production readiness; payment and pilot evidence is recorded separately in the milestone status.

## Manual payment records and allocations

**Proposal / implementation defaults:** owner-only, private historical receipt records in both workspace types. These are reconciliation entries against drafts, never proof of invoice issuance, a payment instruction, or a subscription purchase.

- `POST /finance/payments`: `{currency, amount_minor, received_on, evidence_reference, correction_of?}`. Positive canonical minor-unit strings have the same signed-64-bit ceiling as drafts. Dates use `YYYY-MM-DD`. Evidence references are trimmed and ASCII case-folded for duplicate detection within the workspace; voided references remain reserved. A correction uses a new reference and links to an already voided record. This detects repeated references, not independent evidence authenticity or bank duplicates.
- `GET /finance/payments`: Inertia or JSON `{workspace, payments, nextCursor}`. UUID cursor, 25 records per page, ordered by creation time and ID. Each record includes exact amount/allocated strings and allocation/reversal history. Draft lists use the same ordering.
- `POST /finance/payments/{id}/allocate`: `{version, invoice_id, invoice_version, amount_minor}`. A receipt can be split across drafts and several receipts can cover one draft. Currency must match; both balances bound each positive allocation. Versions must match and neither target may be voided. Allocation increments the payment version. Allocations do not issue or mark a draft as an official paid invoice.
- `POST /finance/allocations/{id}/reverse`: `{reason}`. Append one permanent full reversal; never overwrite/delete the allocation. Partial corrections reverse the original allocation and then record the corrected allocation. Reversal does not increment the payment version; all balance checks run again under the workspace lock.
- `POST /finance/payments/{id}/void`: `{version}`. Reverse active allocations first. The receipt, evidence reference and history remain. Amount, date, currency and evidence cannot be edited in place.
- Every mutation uses the same UUID command header/form convention, payload conflict handling and authorization-before-replay as drafts. Per-workspace PostgreSQL transaction locks serialize invoice edits, payment commands and allocation balance checks. Receipt retries and allocation retries produce one record/audit. Failed commands reserve no key.
- Active allocations prevent editing/voiding the target draft until reversed. PostgreSQL triggers additionally enforce allocation currencies/bounds, receipt field immutability, terminal voiding and allocated-draft update protection. Composite foreign keys prevent foreign-workspace targets. All four payment tables have forced RLS; allocation/reversal/command history has no runtime update/delete policy. Application roles and partnerships do not confer finance access.
- The UI exposes receipt capture, named receipt/draft selectors (with draft paging), reversal reasons, explicit void confirmation and cursor history. Versions are supplied from the selected stored records; users do not enter database IDs or optimistic versions for allocations. Errors preserve entered details and offer reload; no conflict silently retries a newer version. No evidence upload, bank feed, tax treatment, refund, transfer, or external action is implemented.

## Explicit pilot plans and entitlements

**Proposal / implementation defaults:** plans are immutable versions, assignments are append-only, and administration is CLI-only using the migration role in local/testing environments. Owners can view `/workspace/entitlements`; runtime users cannot create/change plans, assignments or administration events. Every administrative command requires an operator label and reason and records an event in the same transaction. These operator labels attribute local administration; they are not a production operator identity/step-up mechanism.

Supported create-limit resources are `pipeline_entries`, `brand_contacts`, `reminders`, `onboarding_requests`, `outreach_templates`, `invoice_drafts`, and `manual_payments`. Limits count currently retained rows, including completed reminders and voided finance records; supported deletion frees a slot. They are record counts, **not** an approved definition of “active brands.” No currency prices, commercial plan names, numerical limits or pilot assignments are seeded. No subscription charge is inferred from a manual receipt.

- An unassigned workspace stays explicitly `unassigned-development`, preserving the existing development baseline. It is not a free commercial plan or launch authorization.
- An assigned plan has explicit integer quotas from 0 through 1,000,000,000. An omitted resource has a zero create limit. No implicit unlimited entitlement exists within an assigned plan.
- The newest assignment version takes effect as the selected assignment immediately. It permits creates only during `[starts_at, ends_at)`. A future start blocks creates until that start; expiry never falls back to an older assignment. Each assignment requires an explicit end date. Replacing it with another time-limited assignment changes limits without deleting data.
- PostgreSQL insert triggers serialize quota counts with assignment changes using a workspace advisory lock. Enforcement covers domain services, direct runtime inserts and public lead capture. The quota definer routine is not executable by runtime callers and checks authorization before counting. A valid public lead capability permits only its existing capture path. Replays return their original result without consuming another slot.
- Downgrade/expiry blocks new resource rows but preserves existing read/edit/void/reversal access under the original roles. Rows above a new limit remain; no automatic deletion, payment or upgrade occurs. Quota failures return 422 with a generic `quota` error and are excluded from normal SQL exception reporting to avoid logging expected rejected form values.

Run from the repository root after migrations/grants (substitute locally approved synthetic plan/limits and IDs):

```sh
docker compose --env-file src/.env run --rm --no-deps migrate php artisan pilot:plan 'Local pilot' 1 '{"pipeline_entries":20,"invoice_drafts":5}' --operator='local-admin' --reason='Approved local pilot experiment'
docker compose --env-file src/.env run --rm --no-deps migrate php artisan pilot:assign WORKSPACE_UUID PLAN_UUID '2026-10-23T00:00:00+00:00' '2026-11-23T00:00:00+00:00' --operator='local-admin' --reason='Approved local pilot experiment'
```

The example numbers are **Proposal / example only**, never defaults. Pricing, active-brand semantics, numerical commercial quotas, renewals/grace, storage/member/API/AI limits, production operator authorization/MFA and retention policy remain **Assumption / Open**. Production administration and commercial launch require those separate decisions.
