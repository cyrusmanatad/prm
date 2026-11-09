# M5-08 delivery operations verification — 2026-11-09

Status: **implemented and locally verified; production budget/provider/recovery acceptance open**. Branch `m5/delivery-operations-2026-11-09`, working tree based on `23f1f60`. [Contract](delivery-operations-contract.md); [confirmed D30 policy](../shared/decisions.md#d30--follow-up-delivery-and-uncertainty-operations). M5/M6 are incomplete.

## Delivered and verified

Explicit approved-version outreach, default-off account-only review notices, atomic stale-intent cancellation, fixed-minute PostgreSQL budgets, fair dispatch leases/backoff and scoped MFA diagnostics/resolution are implemented. Uncertain sends never become retryable. Operator projections exclude recipients, content, capability ciphertext/URLs and raw errors. The existing manual path and tenant privacy remain usable without AI or mailbox integration.

| Gate | Result |
| --- | --- |
| Fresh real PostgreSQL install | All migrations/runtime grants passed in `weavdash_m5_delivery_ops_final_20261109`. The earlier isolated development database is `weavdash_m5_delivery_ops_20261109`; no shared/staging migration occurred. |
| Full PostgreSQL/Redis acceptance | **136 tests / 3,075 assertions passed** with dedicated Redis prefix `weavdash_m5_delivery_acceptance_`. Fourteen new M5-08 tests cover exact recipient/content, one intent per version, account-only generic review, consent history/withdrawal, cross-owner RLS denial, edit/reply/revocation cancellation, durable budgets/backoff, lease/queue loss, round-robin selection and fair returned order, scoped offline grants/MFA/stale proof/revocation, resolution/audit rollback, direct operational-table write denial and history-preserving rollback refusal. Invalid/negative budget configuration defers safely. |
| Real concurrency and transport | The suite observes actual PostgreSQL blocking in both reply-versus-outreach-reservation orders and between competing workspaces for a global budget slot. Actual Redis worker → loopback SMTP captures exact approved subject/body, verified-owner Reply-To, plain-text MIME and stable Message-ID without CRM interpolation. Replays do not send again. No external recipient is contacted. |
| PostgreSQL integration | **35 tests / 635 assertions passed** against the final schema. |
| PHP unit/feature | **42 tests / 362 assertions passed**. |
| Migration rollback/reapply | Both M5-08 migrations rolled back/reapplied successfully before new-format history existed, retaining **1 lead, 1 draft, 1 old-format notification and 9 audit rows**, plus identical notification routing/state fingerprint. Runtime grants were reapplied. Full acceptance then passed on that schema. Tests verify both down methods refuse once corresponding new delivery/resolution history exists, preserving it instead of deleting it. No restore drill is claimed. |
| Frontend/PHP quality | Changed-PHP Pint and pinned Prettier passed; **10 supported frontend files checked**, `npm run typecheck`, **75 component tests / 17 files**, and `npm run build` passed. |
| Chromium interactions | Broad affected run: **9 journeys passed / 3 existing mobile variants skipped**. Covers new sending/MFA resolution and directory navigation, existing manual approval/download/edit/discard, stale lead approval, confirmed reply, and reminder consent → real queued local capture → history → withdrawal. Final extracted-form recheck: **4 desktop/mobile journeys passed**. Existing broad small-screen acceptance stays with Claude under D28. |
| Rendering/accessibility | New flows pass Axe with zero violations; diagnostics checked for no horizontal overflow at **320/768/1024/1440×1000**. Initial Cancel focus, send Escape/trigger restoration, separate send consent, filtered resolution return, resolved controls removal and read-only permissions are checked. Directory navigation has a 44px target. Temporary Automation/directory/diagnostics captures were inspected for the existing palette, typography, cards, focus and responsive controls, then removed. |
| Source scan | Positive control passed. Scan is **not clean**: the two unchanged SHA-256 evidence entries at `planning/evidence/m5/schedules/source-sha256.json:7` and `planning/evidence/pipeline-drag/source-sha256.json:10` still match the generic-key rule. They were previously inspected as hashes; no rule or suppression was changed. |
| Documentation | Updated decision, contract, indexes, task register, roadmap and handoff; **505 local file links** and `git diff --check` passed. |

Reuse: FoundationShell, ContentSection, FormField, SelectField, ActionGroup, Button, shared confirmAction/ConfirmationDialog and validation-focus behavior. `ResolutionForm` extracts operator form state, confirmation, busy state and server-error handling; shared types/options avoid a second resolution vocabulary. No CSS palette or competing layout system is introduced.

Initial broad checks found the expected extra consent event and a missing current-grant setting before the successful MFA redirect; both are corrected. A pre-existing expiry fixture now uses database time for its real-clock deadline. Visual checks corrected an overly long action label and preserved filters; the button uses its existing typed accessibility-label prop. Dispatch review also caught the need to retain fair queue order after acquiring locks in organization order. The final suites above include these corrections; no security assertion or formatting gate was weakened.

## Reproduce locally

Use source-mounted Compose project `weavdash-professional`, PHP 8.5.11/PostgreSQL 16/Redis, with `/tmp/weavdash-pipeline-compose.yaml`. Never substitute a shared database for rollback. Credentials remain in existing ignored environment files. From repository root:

```sh
docker compose --env-file src/.env -p weavdash-professional -f compose.yaml -f /tmp/weavdash-pipeline-compose.yaml run --rm -T --no-deps -e DB_DATABASE=weavdash_m5_delivery_ops_final_20261109 migrate
docker compose --env-file src/.env -p weavdash-professional -f compose.yaml -f /tmp/weavdash-pipeline-compose.yaml run --rm -T --no-deps -e DB_DATABASE=weavdash_m5_delivery_ops_final_20261109 migrate php scripts/grant-runtime.php
docker compose --env-file src/.env -p weavdash-professional -f compose.yaml -f /tmp/weavdash-pipeline-compose.yaml run --rm -T --no-deps -e DB_DATABASE=weavdash_m5_delivery_ops_final_20261109 -e REDIS_PREFIX=weavdash_m5_delivery_acceptance_ acceptance vendor/bin/phpunit -c phpunit.acceptance.xml
docker compose --env-file src/.env -p weavdash-professional -f compose.yaml -f /tmp/weavdash-pipeline-compose.yaml run --rm -T --no-deps -e DB_DATABASE=weavdash_m5_delivery_ops_final_20261109 acceptance vendor/bin/phpunit -c phpunit.pgsql.xml
```

Rollback of the two new migrations uses that migration service/database with `php artisan migrate:rollback --step=2 --force` **only before new delivery/resolution history exists**. After new history exists, refusal is expected. Retain/review backups before any real downgrade; this verification performs no shared rollback or history cleanup. The standalone single-user schema checker was not extended/run; real fresh-schema/runtime RLS tests provide this slice's schema evidence.

The temporary browser app is Compose project `weavdash-m5-delivery` at `127.0.0.1:8001`, configured by `/tmp/weavdash-delivery-compose.yaml`, using the final synthetic database, array mail and Redis prefix `weavdash_m5_delivery_browser_`. Those identities were checked before running workers. No shared queue flush or service restart was performed. The temporary app is stopped after verification. Start it to repeat browser checks; from `src/`:

```sh
npm run format:check:changed
npm run typecheck
npm test
npm run build
COMPOSE_PROJECT_NAME=weavdash-m5-delivery DELIVERY_TEST_COMPOSE=/tmp/weavdash-delivery-compose.yaml PLAYWRIGHT_BASE_URL=http://127.0.0.1:8001 npx playwright test delivery-operations.spec.ts approval-basis.spec.ts follow-up-drafts.spec.ts logged-reply.spec.ts scheduled-delivery.spec.ts --grep-invert 'scheduled preparation' --project=desktop --project=mobile --reporter=list --output=/tmp/weavdash-m5-ops-browser
```

The temporary Compose files are local environment aids, not deployment artifacts. New/updated success captures are opt-in under a `/tmp/` directory; no new image evidence is retained in the repository. A later rollout must pause dispatch/workers, apply the additive migrations through the migration role, replace app/worker/scheduler code and assets together, then resume after checks; old workers cannot safely consume new notification kinds. No rolling-version compatibility is claimed. No shared demo/account cleanup, real operator provisioning, staging/production deployment or provider trial occurred.

## Remaining gates and next step

**Next independent backend work: M5-10 synthetic recovery** on an entirely disposable service stack: actual Redis/service restart, full workflow reconstruction, outages and the remaining recovery matrix. Current scalar replay, queue-failure, concurrency and SMTP probes contribute scoped evidence, not the whole gate.

**M5-09** still requires separately authorized recipient-controlled queued provider delivery and link-opening trials. Actual per-minute limits, provider rolling/daily/spend caps and production settings remain open under M5-08; zero means unconfigured, not an approved production allowance. ADM-05 job/scheduler health, remaining administration, UI-05/broader small-screen review, manual screen-reader/zoom/native Safari/WebKit, current-candidate remote CI/clean setup, privacy/commercial and inherited production gates remain open. M5/M6 completion is not claimed.
