# Native pipeline dragging verification

**Confirmed implementation, 2026-11-08.** Reference: `bel/initial` at `c5b91c6f8914cac7f65d67898b04e333aa039beb`. Read with `git show` after fetching that branch; no checkout, merge or cherry-pick occurred. Current branch remains `m5/onboarding-lifecycle-email`, HEAD `69755705274115339372d3f0153ace9e191fdfaf`, with the pre-existing uncommitted Professional Hub implementation preserved. See the [pipeline contract](pipeline-contract.md) and [current handoff](../shared/implementation-handoff.md).

## Implementation

Reused `PipelineBoard`, `PipelineColumn`, `LeadManager`, existing currency helpers, shared fields/buttons and the established portal styles. Native dragging uses the reference grab cursor, 0.45 dragged-card opacity, dashed valid targets, brand-colored hovered targets and 0.7 pending-card opacity. No drag dependency or touch-drag system was added. Existing column visibility, creation order, filters, pagination and exact separate currency totals remain in place.

Dragging requires write permission and active workspace access. Static fixtures default to non-draggable. Loading, drawer interaction and pending mutations block dragging. Same-stage/external drops, cancellation and duplicate moves are covered. The displayed version and current server filters go to `POST /pipeline/{entry}/stage` with a validated boolean `board=true`. The controller returns to the filtered board; normal drawer stage changes retain selected-lead redirects. Existing Professional route mapping, RLS, role checks, subscription guards, audit and activity services are reused.

The moved card changes stage optimistically; the response supplies authoritative cards, counts and totals. Pending feedback explicitly explains the aggregate refresh. Rejections restore server props and retain a persistent alert. Version rejection reloads the filtered board without retrying the write. A deliberate later move uses the refreshed version. Keyboard/mobile users retain the drawer stage selector.

## Environment and commands

Reused the existing isolated `weavdash-professional` PostgreSQL/Redis/ClamAV volumes; no migration, database reset, deployment or external message was needed. Started its test app on `http://localhost:8000` with the current source and production bundle mounted as UID 1000. Existing other-project data and worker/scheduler processes were preserved. `/tmp/weavdash-pipeline-compose.yaml` supplies source mounts for app/migrate/acceptance and the existing isolated private-storage directory for acceptance. A temporary one-off app container was stopped before the standard Compose app started.

Run npm/PHP tools in `src/`; Compose commands at the repository root with `--env-file src/.env -p weavdash-professional -f compose.yaml -f /tmp/weavdash-pipeline-compose.yaml`.

| Gate | Command / result |
| --- | --- |
| Components | `npm test`: **62 tests, 14 files passed** |
| PostgreSQL pipeline integration | `run --rm -T --no-deps acceptance vendor/bin/phpunit -c phpunit.pgsql.xml --filter PipelineTest`: **8 tests, 141 assertions passed** |
| PostgreSQL role/RLS/subscription acceptance | `run --rm -T --no-deps acceptance vendor/bin/phpunit -c phpunit.acceptance.xml --filter 'PipelineRoleTest\|ProfessionalHubTest'`: **14 tests, 218 assertions passed** |
| Formatting | Pinned `npm run format:changed`; final `npm run format:check:changed`: **31 changed frontend files checked** |
| Types | `npm run typecheck`: passed |
| Production bundle | `npm run build`: passed; [build output](../evidence/pipeline-drag/build.txt) |
| PHP style | `vendor/bin/pint --test app/Http/Controllers/PipelineController.php tests/Integration/PipelineTest.php tests/Browser/pipeline-access-fixture.php`: passed |
| Browser | **12 desktop/mobile journeys passed**; [final output](../evidence/pipeline-drag/browser-final.txt) |
| Whitespace and documentation | `git diff --check` and relative-link validation passed |

Component coverage includes writer/static/viewer/loading/pending permissions, local versus external dragging, valid targets, Escape/drag-end/same-stage cleanup, duplicate prevention, optimistic exact-money presentation, rejection rollback, authoritative reconciliation and stale filtered reload without retry.

Browser command: `COMPOSE_PROJECT_NAME=weavdash-professional npx playwright test pipeline.spec.ts pipeline-filtering.spec.ts pipeline-drag.spec.ts --project=desktop --project=mobile --output=/tmp/weavdash-pipeline-verified-browser --reporter=list`. Coverage includes actual desktop native dragging and reload persistence, current filters, no drawer opening, stale recovery followed by a deliberate fresh move, pending mutation feedback/disabled dragging, active Professional route selection, inactive Professional/viewer rejection, keyboard/mobile stage selection, existing seven-stage/activity/delete/revocation journeys and Axe scans.

The first broad run had nine passes and three test failures: two expiry expectations used 403 where the existing database subscription guard returns 422, and the mobile save check matched two valid queued notices. Tests now expect the established 422 and select the newest save notice. A screenshot follow-up also attempted a fresh drag before the stale-board reload finished; the helper now waits for `draggable=true`. No application guard or timeout was relaxed. The [intermediate capture run](../evidence/pipeline-drag/browser-follow-up.txt) is retained; final evidence is in `browser-final.txt`.

## Screenshot review

- [Desktop drag targets](../evidence/pipeline-drag/desktop-drag-target.png): matching reference opacity and target treatments; existing portal styling retained.
- [Desktop pending move](../evidence/pipeline-drag/desktop-pending.png): optimistic target card, disabled controls, retained server counts/totals and explicit pending feedback.
- [Desktop board](../evidence/pipeline-drag/desktop-board.png) and [mobile board](../evidence/pipeline-drag/mobile-board.png): shared responsive board and horizontally scrollable columns; keyboard/mobile selector journey retained.

All four screenshots were visually inspected, including the final horizontally scrolled mobile card. [Source hashes](../evidence/pipeline-drag/source-sha256.json) identify the tested implementation and test files. Screenshots use synthetic accounts and records. Native Safari/WebKit dragging, actual assistive-technology review and production release are unverified; inherited milestone/release gates remain open. M5-07 remains the next unrelated backend slice. No reference branch merge occurred.
