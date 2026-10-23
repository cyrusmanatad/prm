# M2 manual outreach template contract

**PDF:** R02 calls for outreach merge templates. This slice implements workspace-private plain-text templates and a manual draft composer. **Proposal / implementation default:** template fields are name, optional subject, and body; merge fields are `brand_name`, `contact_name`, and `creator_name`. This does not resolve D05 or introduce automated outreach.

## Web and JSON routes

All routes require a verified session, a selected workspace, locked active membership, and transaction-local PostgreSQL RLS, using the same boundary as the pipeline. Browser mutations redirect to `/outreach-templates`; explicit JSON clients receive JSON.

| Route | Input | Result |
|---|---|---|
| `GET /outreach-templates` | None | `OutreachTemplates` Inertia page or JSON; workspace, canWrite, saved templates |
| `POST /outreach-templates` | `name`, optional `subject`, `body` | Create at version 1; JSON 201 with ID |
| `PATCH /outreach-templates/{template}` | Complete details and `version` | Lock and update; increment version |
| `DELETE /outreach-templates/{template}` | `version` | Lock and delete; browser requires explicit confirmation |

Name is required, at most 160 characters; subject at most 240; body required, at most 5,000. Empty subject is stored as an empty string. IDs are UUIDs. Version is an integer from 1 to 2147483646. Organization/actor/version fields cannot override creation ownership. Projections allow only ID, name, subject, body, and version; there are no deferred tenant queries. The current list returns all workspace templates sorted by name and ID; pagination/search remains a scaling follow-up.

Owners/admins/managers/members can save/delete; viewers can read templates and prepare local drafts. Forced RLS independently denies viewer writes and foreign reads/inserts/updates/deletes. Foreign route IDs return 404; invalid inputs 422; missing workspace 409; revoked membership 403. Conflicts return JSON 409 or a browser version error, preserving unsaved fields and blocking further edits until explicit reload. Redacted audit events record create/update/delete without template content. Writes and audit share a transaction.

## Merge and copy behavior

The composer accepts `{{brand_name}}`, `{{contact_name}}`, and `{{creator_name}}`, allowing whitespace inside braces. It substitutes literal values in one pass. Values containing HTML, dollar replacement syntax, or further placeholders are never evaluated. Vue renders all content through text/input bindings; no HTML or template execution occurs.

Unknown, blank-valued, and malformed placeholders remain visible for correction and block generation. Saved templates may contain them so users can edit unfinished templates. Generated subject/body are editable separately from saved template content. Changing source text or merge inputs requires generating a fresh draft. Copy writes subject, two newlines, and body to the clipboard, with a manual-copy fallback message if unavailable. The browser test captures the clipboard write; it does not prove host clipboard integration.

Drafts and merge values stay in page memory and are cleared when workspace changes or the page is left/reloaded. Saving/deleting operates on template text only. No lead lookup, recipient selection, sending, notification, queue dispatch, or delivery status is implemented.

## Verification and next work

See [retained template evidence](evidence/m2/outreach-templates.json) and [M2 status](m2-status.md). Desktop/mobile Chromium journeys cover persisted CRUD, stale-tab draft retention and reload, literal merging, editable drafts, captured copy, viewer mode, and revocation. Real PostgreSQL tests cover foreign IDs and partial props, direct RLS operations, version errors, input limits, forged ownership, redacted audit, and rollback; five-role acceptance checks cover write permissions and revocation. WebKit, native Safari, screen-reader, full browser zoom, and actual host clipboard review remain pending for this slice.
