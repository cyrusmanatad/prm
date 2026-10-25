# M2 contacts and reminder contract

**PDF R02:** reusable private contacts, next actions and reminders. **Proposal / implementation defaults:** contacts contain name, email, company and private notes; reminders contain a title, UTC due time and optional related lead. Owners, admins, managers and members can write; viewers can read. These are private workspace records, with forced PostgreSQL RLS and the existing locked-membership transaction boundary.

## Routes and data

| Route | Behavior |
|---|---|
| `GET /crm` | Contacts and reminders page, or explicit JSON projection; optional selected `entry` UUID |
| `POST /contacts`, `PATCH /contacts/{id}`, `DELETE /contacts/{id}` | Create, replace details, or delete a reusable contact |
| `POST /reminders`, `PATCH /reminders/{id}`, `DELETE /reminders/{id}` | Create, replace details, or delete a reminder |
| `POST /reminders/{id}/complete`, `/reopen`, `/snooze` | Complete, reopen, or persist a future due time |
| `POST /pipeline/{entry}/contacts`, `DELETE /pipeline/{entry}/contacts` | Link or unlink a reusable contact using `contact_id` and the lead's `version` |

Contact name is required (120 characters); email optional (255), company optional (160), and notes optional (5,000). Reminder title is required (240); due time must include `Z` or a numeric offset. Creation may record an already-due task. Snooze must be in the future and reopens a completed reminder. Completion records a server timestamp; reopening clears it. Reminder delivery belongs to M5.

Every update/delete/command requires the displayed record's positive integer `version`; contact-link commands use the lead version. Row locks serialize writes, and stale versions return JSON 409 or web validation errors. Browser drafts remain intact on conflicts and further writes stop until explicit reload. Linking and reminder commands preserve unrelated contact drafts. Creation ignores forged ownership/version fields. Redacted audit and data changes commit or roll back together.

A contact can be linked to several leads. Its latest details appear everywhere it is linked. Unlinking preserves the contact; deleting a linked contact is rejected until all links are removed. A same-owner composite foreign key protects each link and related reminder. Deleting a lead removes its links and related reminders, preserving reusable contacts.

## Browser filter context

**UI-06 (2026-10-25):** `/partner/contacts` is the canonical browser page; legacy `/crm` browser visits redirect with their query preserved. Reminder segment clicks immediately request server results. The returned filter state controls pressed buttons and synchronizes search/Back/Forward; filter changes restart paging. Local drafts survive ordinary filter visits. Counts and reminder summary badges explicitly cover the current page, and notes are private to authorized workspace readers.

Browser mutations may supply optional validated `crm_filters` containing only `q` and `state`, plus optional `entry`. Context validation happens before any write. Successful mutations preserve this context in a canonical redirect and drop cursors; callers omitting context retain the existing default redirect/JSON contract. Explicit stale-record reload also preserves filters and resets paging. Stale drafts remain intact with accessible, persistent editor feedback until the user copies changes and reloads. No schema or permissions change. See [verification and screenshots](evidence/crm-repair-2026-10-25/README.md).

## Lists and UI

The pipeline links to the directory and to a selected lead's contact/reminder view. The selected view shows existing links and offers linking from the directory; it does not copy shared contacts into the lead's legacy embedded contact fields.

Contacts filter by literal, case-insensitive substring of name/company/email (`q`, at most 160 characters). Reminders filter by `state=pending|due|completed|all`; pending is the default. `contacts_cursor` and `reminders_cursor` page independently through 25 rows ordered by creation time/ID descending. Inaccessible or no-longer-matching cursors return 404. An optional `entry` supplies link context and defaults new reminders to that lead; reminder lists still cover the workspace. Dates display in browser local time and store in UTC.

Projections omit organization and actor attribution. Missing selection is 409; revoked membership 403; inaccessible IDs 404; invalid fields 422. Selected-lead context and related IDs are checked within the current tenant before use. Dedicated reminders do not alter the pipeline's separate next-action timestamp.

See [M2 status](m2-status.md), [pipeline contract](m2-pipeline-contract.md), [manual template contract](m2-outreach-template-contract.md), and [contact/reminder evidence](evidence/m2/crm.json).
