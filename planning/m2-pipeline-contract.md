# M2 private pipeline contract

Implementation scope: first creator CRM slice. These web routes share the session/CSRF, verified identity, selected-workspace, locked membership, and transaction-local RLS boundary. No external API token or organization header is accepted.

## Routes

| Route | Input | Result |
|---|---|---|
| `GET /pipeline` | Optional `q` (brand substring, at most 160 characters), `stage`, `due=1`, `cursor` UUID, `entry` UUID | Inertia `Pipeline` page, or explicit JSON projection |
| `POST /pipeline` | Lead details below | Create at Lead stage; redirect to selected lead; JSON 201 with ID |
| `PATCH /pipeline/{entry}` | Complete lead details and `version` | Save details; increment version |
| `POST /pipeline/{entry}/stage` | `stage`, `version` | Manual stage change and private activity event |
| `POST /pipeline/{entry}/snooze` | Future `next_action_due`, `version` | Persist next-action date; increment version |
| `POST /pipeline/{entry}/activities` | `kind` (`note`, `email`, `chat`, `call`), `body` (1–5,000 characters), `version` | Append manual activity; increment version; JSON 201 |
| `DELETE /pipeline/{entry}` | `version` | Delete lead and private activity history; append redacted audit |

Lead details: required `brand_name` (1–160 characters), decimal-string `value_minor` (0–999999999999999999), and uppercase three-letter `currency`; optional `contact_name` (120), `contact_email` (255), `notes` (5,000), and `next_action_due`. Dates must contain an explicit UTC `Z` or numeric offset; storage is UTC. Browser forms convert local datetime input and decimal money to this contract. Blank nullable fields clear their values on update. A new lead always starts at version 1 and stage `lead`; supplied organization, actor, role, stage, and version fields cannot override creation attribution.

Stages: `lead`, `pitched`, `negotiating`, `onboarded`, `active`, `repeat-client`, `lost`. **Proposal / implementation default:** manual moves between these stages are allowed. A move does not initiate onboarding, send outreach, or schedule a job. D05 remains open before M3 behavior.

## Read projection and errors

The page includes selected workspace ID/name, `canWrite`, stage options, filters, entries, stage counts, per-currency totals, optional selected detail, and a next cursor. Lists omit private notes and activity bodies; explicit selected detail includes them within the current workspace. Activity projection omits actor identity and organization fields and exposes the latest 50 events. Account props retain the existing allowlist. No lazy/deferred query escapes the tenant transaction.

Entries sort by creation time and ID descending, 25 per page. The cursor identifies a row in the current filtered workspace; inaccessible or no-longer-matching cursors return 404. Counts and totals cover all matching rows, independent of page; totals exclude Lost and never combine currencies. Aggregate money remains a decimal string even when it exceeds signed-64-bit or JavaScript integer range.

Board presentation (**Confirmed**): a stage count appears beside the stage name only when above zero and is not part of the heading's accessible name. A stage with no entries on the current page shows a placeholder card: "N leads on other pages" when its count is above zero, "No matching leads" when filters are active, and otherwise "No leads yet". Presentation does not change props or queries.

Owners/admins/managers/members can write; viewers read only. RLS separately enforces this default. Missing selection returns 409; absent/revoked membership returns 403. Inaccessible row IDs return 404. Invalid fields return JSON 422 or web session validation errors. Version conflicts return JSON 409 or a web redirect with a `version` error. Stale commands cannot append successful activity/audit events. Commands acknowledge their own version increment while preserving unsaved details, even if another writer edits during the redirect. Browser drafts survive the error and saves remain blocked until explicit reload.

Leads with onboarding history are retained: deletion returns a conflict explaining how to revoke invitation access. The immutable submission retention/deletion workflow remains a release decision.

## Pipeline UI repair (2026-10-25)

The partner board queries the existing server listing for search, stage/Repeat Client/Lost segments and due filters. “All leads” includes all seven stored stages; the five main columns remain Lead/Pitched/Negotiating/Onboarded/Active, with separate labelled Repeat Client/Lost sections. Choosing a filter resets the cursor; page navigation and opening/closing a drawer preserve the current URL context. Browser history restores the controls and cancels an unsubmitted debounced search.

Stage counts and matching non-lost headline totals retain the all-matching-pages semantics above. Column money is explicitly labelled **Page value**; no cross-currency addition or false whole-workspace total is implied. A stage with matching rows elsewhere shows its all-page count and an other-pages placeholder. The shared SelectField replaces the board-only dropdown; PipelineColumn reuses the same card/placeholder presentation for all seven stages.

Mutations optionally accept `pipeline_filters` with only `q`, `stage` and `due`, validated before writing. Success redirects retain these filters and the selected lead, restarting at the first matching page because a moved/deleted cursor may no longer match. No caller-supplied tenant, cursor or redirect URL is accepted in this context. Existing JSON results, authorization, versions, stage values and storage remain unchanged. See [repair evidence](evidence/pipeline-repair-2026-10-25/README.md).

## Follow-on scope

Embedded contact fields are not reusable contact management. Dedicated reminders/completion remain M2 work. The separate [manual outreach template slice](m2-outreach-template-contract.md) is implemented and linked from the pipeline. Notifications, delivery scheduling, and durable external actions remain M5. See [M2 status](m2-status.md).


## Planned portal presentation (D22)

**Confirmed:** the M3A partner board will show Lead, Pitched, Negotiating, Onboarded and Active, matching the supplied reference. Repeat Client/Lost UI is optional later. This changes presentation only: existing seven-stage backend support, database structures, stored records and aggregate semantics remain unchanged. Current M2 behavior/evidence above describes the existing implementation; no application code is changed by this planning update. See [portal UI alignment](portal-ui-alignment.md).
