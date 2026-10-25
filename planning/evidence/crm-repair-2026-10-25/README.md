# CRM filters and shared presentation — 2026-10-25

## Scope and behavior

**Confirmed:** milestone continuation selected UI-06 from the current handoff, based on `7d74f30` on `ui/audit-design-refinement`. UI-06 is complete for scoped local automated acceptance. M5 remains incomplete; UI-07 feedback is the next implementation task, followed by remaining UI-05 reference/deviation acceptance before M5-02. Inherited release gates remain open.

- Pending, Due, Completed and All immediately request server-filtered reminders. Pressed state follows returned filters rather than an unsubmitted local selection. Requests disable duplicate actions, preserve unrelated editor drafts and reset cursors when filters change. Returned props synchronize search and selected state on Back/Forward. Contact search remains an explicit form submission and does not search reminder titles.
- Contact/reminder saves, deletion, completion, reopening, snooze and lead linking retain validated `crm_filters` (`q`, `state`) and selected-lead context while restarting paging. Context validation runs before writes, rejects unknown/invalid filters and preserves the literal query `0`. Existing tenant permissions, service rules and schema are unchanged.
- Contacts and reminder summary badges explicitly describe the current page. Notes and reminders describe workspace privacy, including authorized workspace readers, without changing access rules.
- Shared ContentSection supplies all three card surfaces through an optional header slot; existing consumers keep their default header. Shared Button and ActionGroup replace the parallel CRM button/action system. FormField now also supplies the search control, and its established field styles replace CRM overrides. Feature-specific rows, avatars, badges, segmented controls and layout remain local. A typed `ariaLabel` prop forwards record-specific button names; a regression test covers the native accessible label.
- Stale edits retain drafts, block writes and show focused, persistent feedback inside the modal as well as recovery outside it. After copying drafts and closing the editor, Reload latest records preserves filters and resets paging. Shared destructive confirmation behavior remains intact.

## Verification

| Gate | Result |
|---|---|
| Pinned changed-file formatting/check | Pass, six supported frontend files |
| TypeScript | Pass |
| Component tests | 48 passed in 12 files, including pending/applied state, cursor reset, returned-prop synchronization and accessible button names |
| Production build | [Pass](build.txt) |
| PostgreSQL CRM integration | 3 tests / 54 assertions; CRUD/linking, snooze/completion, stale versions, cross-tenant IDs/cursors, direct RLS denial and filter-context redirects/rejection |
| PostgreSQL CRM roles/revocation | 1 test / 40 assertions; all five roles, revoked membership and direct RLS denial |
| PHP unit/feature regression | [29 passed / 256 assertions](php.txt) |
| Changed PHP Pint | Pass, controller and integration test |
| Chromium desktop/mobile | [6 passed in 2.0 minutes](browser.txt); unchanged Axe assertions pass |
| Source scan | [Positive control and 708-file source scan pass](secrets.txt); no findings |

The final browser run covers 27 contacts and 27 mixed-state reminders, paging, older-record search, cursor resets, immediate segment selection, Back/Forward, filter-preserving mutations, retained draft tabs, keyboard completion, stale errors/reload, Escape/trigger restoration, nested delete/unlink confirmations and viewer access. The existing shared-control journey also checks CRM and adjacent pages at 320, 390, 768, 1024 and 1440px, keyboard dialogs/selects, mobile capture/save and unchanged accessibility rules. An earlier exploratory run exposed missing record-specific accessible button names during shared-component conversion; the forwarding fix and dedicated regression test precede the final passing run.

Planning relative-link and whitespace checks also pass.

The final app runs in local Compose project `weavdash`, using synthetic fixtures and local array mail. The app/acceptance images were rebuilt; the checked final frontend bundle was copied into the running app afterward. The local manifest is `cab89dacc3b9495f4cdb5cb9a21077bca5c798e6a1986d0dd286af85576ad92c`. [Source hashes](source-sha256.json) identify the tested working-tree files. No production deployment, push or external email was performed.

## Visual evidence

- Linked contacts and completed empty state: [desktop](crm-desktop.png), [mobile](crm-mobile.png).
- Populated filtered directory and reminder page: [desktop](crm-paged-desktop.png), [mobile](crm-paged-mobile.png).
- Retained stale-edit draft and persistent conflict feedback: [desktop](crm-stale-editor-desktop.png), [mobile](crm-stale-editor-mobile.png).
- Responsive empty directory: [320px](crm-320.png), [768px](crm-768.png), [1024px](crm-1024.png), [1440px](crm-1440.png).

Inspected populated desktop, mobile stale editor and 320px empty screenshots: established burgundy/cream palette, shared surfaces/controls and stacked mobile composition remain aligned; no document overflow is observed. Long full-page modal captures include background content outside the viewport; the modal backdrop covers the viewport, and the editor scrolls within its bounds. These CRM checks do not establish broader portal-reference acceptance under UI-05.

## Commands and remaining gates

From `src/`:

```sh
npm run format:changed
npm run format:check:changed
npm run typecheck
npm test
npm run build
COMPOSE_PROJECT_NAME=weavdash npm run test:browser -- crm.spec.ts ui-refinement.spec.ts --project=desktop --project=mobile --output=/tmp/weavdash-crm-final-browser --reporter=list
```

From the repository root:

```sh
docker compose --env-file src/.env -p weavdash build app acceptance
docker compose --env-file src/.env -p weavdash up -d --no-deps --wait app
docker cp src/public/build/. weavdash-app-1:/var/www/html/public/build/
docker compose --env-file src/.env -p weavdash exec -T app vendor/bin/phpunit -c phpunit.pgsql.xml --filter CrmTest
docker compose --env-file src/.env -p weavdash run --rm -T --no-deps acceptance vendor/bin/phpunit -c phpunit.acceptance.xml --filter CrmRoleTest
docker compose --env-file src/.env -p weavdash exec -T app php artisan test --compact
docker compose --env-file src/.env -p weavdash exec -T app vendor/bin/pint --test app/Http/Controllers/CrmController.php tests/Integration/CrmTest.php
python3 src/scripts/check-secrets.py
```

Sandbox process/Docker restrictions required approved execution outside the sandbox. Native Safari, Linux WebKit, actual assistive technology, full browser zoom, full backend acceptance, clean setup, final-candidate remote CI and provider receipt remain unverified by this slice. UI-07, broader UI-05 acceptance and inherited M1–M6 release work remain indexed in the [remaining-task register](../../remaining-tasks.md).
