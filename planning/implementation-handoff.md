# Current Implementation Handoff

Updated: 2026-10-25. This is the current resume point; older milestone handoffs retain historical evidence and instructions for their original scope.

## Checkpoint and milestone state

- **Latest UI review/checkpoint (2026-10-25):** reviewed the complete update on `ui/audit-design-refinement`, based on `2a5b7bb`. Preserve and checkpoint the existing UI, controller feedback, dependency and test changes together with this planning review. UI-03/UI-04 have scoped local acceptance; the revision as a whole remains pending acceptance. Added **UI-06** for the newer CRM layout's disconnected reminder filters, page-only count/privacy copy and shared-component consolidation, and **UI-07** for queued-email truthfulness and global toast acceptance. See [current review and validation](evidence/ui-plan-review-2026-10-25/README.md). **Next: UI-02 pipeline correctness and UI-05 mobile capture/save**, then finish UI-05–UI-07; resume **M5-02** after these repairs. Earlier branch/uncommitted statements below are historical.

- **UI refinement (2026-10-25):** the user approved a brand-preserving, frontend-only audit proposal. Shared actions, secondary/danger button hierarchy, Add lead cancellation, dialog/select keyboard behavior, accessible account/card names, template/CRM spacing, workspace-security reuse and tablet finance layouts are implemented. Existing uncommitted work is preserved. Formatting/types/build and 42 component tests pass; the five-width responsive journey passes. All 22 affected desktop/mobile journeys pass, including both portal shells; screenshots and Axe assertions pass. See [current evidence](evidence/ui-refinement-2026-10-25/README.md). UI-02 pipeline semantics and the remaining UI-05 fixture/reference work stay open; this does not complete M5/M6 or production release gates.

- **Historical UI review (2026-10-24):** checkout is `ui/audit-design-refinement`, HEAD `eac7de2`, with uncommitted UI changes from another session. Review found regressions in paginated pipeline filtering/counts/stage presentation, select keyboard choice and dialog traversal, plus account-menu/lead-card accessible-name failures and a stale visual fixture. **This UI revision is not accepted.** Formatting/types/build and 32 component tests pass; desktop/mobile browser results are 25 passed, 16 failed and one intentional skip. Preserve the existing implementation; repair and verify **UI-02–UI-05** in the [register](remaining-tasks.md). See the [review, reproduction evidence and validation](evidence/ui-review-2026-10-24/README.md). Branch/checkpoint statements below are historical. M5 remains incomplete; M5-02 remains the next business-feature slice after these UI repairs.

- **M5-01 (2026-10-23):** onboarding invitation and replacement email is implemented on `m5/onboarding-invitation-email`, based on `ce2d9bd`; changes are currently uncommitted. Explicit validated recipient selection, generation-scoped encrypted intent, current authorization/access checks and delivery history reuse the M5 outbox and UI components. Local acceptance passes 53 tests / 1,142 assertions, integration 27 / 503, identity 29 / 256, frontend 26 component tests plus formatting/types/build. Eight affected desktop/mobile journeys pass across the broad run and corrected two-test follow-up; migration rollback/reapply, catalog checks, Pint and source scan pass. See [contract](m5-onboarding-email-contract.md) and [evidence/screenshots](evidence/m5/onboarding-email/README.md). **M5-02 is next; M5 remains incomplete.**

- **UI-01 follow-up (2026-10-23):** shared native modal confirmations replace body/checkbox confirmations and immediate destructive/access-revoking submissions throughout the app, including both partnership portals. Required reasons/passwords, permissions and record versions remain in the original mutation handlers. Routine saves remain immediate. Pinned formatting/types, 26 component tests and build pass. All 34 affected desktop/mobile journeys have passing evidence across the broad run and the 12-test follow-up; the follow-up also closes the inherited M1-06 link target-size finding. Logs and inspected screenshots are retained in [confirmation evidence](evidence/confirmation-modals/README.md); this UI task does not close unfinished M5/M6 or inherited release work.

- Branch: `m5/onboarding-invitation-email`, based on `ce2d9bd` (`ui: require modal confirmation for destructive actions`). UI-01 and the original M5 checkpoint below are historical.
- Application checkpoint: `efd4fd6` — `feat: add durable partner invitation emails`. M4 was previously merged into the baseline. The subsequent documentation commit adds this handoff and the cross-milestone register.
- M1–M4 development is implemented; their release/manual/production gates remain open as recorded. M5's partner and onboarding invitation slices are complete and locally verified; **M5 is incomplete**.
- **M6 preparation has started.** Its readiness contract and dependency register are prepared; operational implementation, drills and final acceptance remain pending. Required M5 work cannot be silently skipped when advancing to M6.
- The current user request authorized inspecting the next task, creating a new branch and implementing it. The follow-up authorizes continuing without additional task approvals for this session. D26's prior M6/carry-forward direction remains recorded; no production deployment, purchase, mailbox access or real trial message was performed.

Read [remaining tasks](remaining-tasks.md) for stable IDs, current states, completion criteria and source links. Read the [M6 readiness contract](m6-readiness-contract.md) before readiness implementation. Maintain both at each handoff; do not remove open work when changing milestone labels.

## Delivered M5 slice and evidence

Implemented: transactionally persisted encrypted notification intent; Redis dispatcher, scalar-ID jobs and minute scheduler; explicit brand-to-partner email selection; delivery/cancellation UI; current actor/invitation checks; canonical configured-origin links; and conservative uncertain-send handling with no automatic resend. The UI reuses `FormField`, shared `Button` and `ContentSection` with the existing portal design.

Local verification at the application checkpoint:

| Gate | Recorded result |
|---|---|
| Full PostgreSQL acceptance | 46 tests, 987 assertions |
| PostgreSQL integration | 27 tests, 503 assertions |
| Unit/identity/feature | 29 tests, 256 assertions |
| Fresh/reapplied M5 schema and notifications | Migration/rollback/reapply/catalog checks; 10 tests, 129 assertions |
| Frontend | Formatting, types, 21 Vitest tests and production build passed |
| Browser/accessibility | 8 Chromium desktop/mobile journeys; 14 Axe scans, zero violations; inspected screenshots retained |
| Source/PHP | PHP Pint, source-secret scan with positive control and whitespace checks passed |

See [M5 evidence and exact commands](evidence/m5/README.md), [status](m5-status.md), [notification inventory](m5-email-notifications.md) and [implemented contract](m5-notification-contract.md). Retained text logs have whitespace normalized; results are preserved. These are local results, not new remote-CI or provider-receipt evidence.

## Next implementation sequence

1. Inspect the current checkout and register before editing; preserve any new user/session work. Repair the pipeline first: **UI-02** server-backed filters/pagination, truthful aggregates and distinct Repeat Client/Lost presentation, plus **UI-05** mobile capture/save pointer interception. Complete **UI-05–UI-07** production-fixture/reference, CRM and notification-feedback acceptance; UI-03/UI-04 have scoped local passing evidence. Then resume **M5-02**: submitted, correction-requested and accepted onboarding emails. Specify authorized recipients and safe usable return links, including expired 24-hour sessions, before implementation. Reuse the existing durable outbox and current UI components. M5-01 invitation/replacement email is locally complete; provider receipt remains M5-09.
2. Complete **M5-03–M5-08**: persistent scheduling/timezones/preferences/consent, reminder/digest delivery, follow-up drafts and approvals, reply cancellation, approval invalidation, rate/transport budgets and uncertain-delivery operator behavior. Optional mailbox syncing and AI are not required.
3. Complete **M5-09/M5-10** delivery/recovery acceptance with a separately authorized recipient-controlled trial. Existing local SMTP capture and historical account-mail trials do not prove current business-notification receipt.
4. Independent M6 preparation/rehearsals can proceed: start with **M6-01** isolated synthetic backup/restore planning/drill and **M6-03/M6-04** monitoring/runbooks, while keeping M5 dependencies explicit. Resolve hosting/region/recovery/pilot/operator decisions before dependent live operations.
5. Close inherited accessibility, MFA, storage/privacy/commercial and final candidate/UAT gates under their register IDs. Finish M6 acceptance only after required predecessor work and applicable release gates pass. Retain future M7–M9 and optional proposal rows for later implementation.

The register is the complete carry-forward index. This sequence prioritizes work; it does not replace or discard other open rows. If work stops mid-task, record the exact ID, files changed, last verified command/result and next step here and in the originating milestone status.

## Local environment and commands

The latest UI review uses Compose project `weavdash` at `http://localhost:8000`; use `COMPOSE_PROJECT_NAME=weavdash` for its browser fixtures. The older M5-01 verification used Compose project `weavdash-m1`, with the rebuilt app running at `http://localhost:8000` and local array mail. Its separate synthetic database is `weavdash_m5_onboarding_20261023`; migration/rollback/reapply and full acceptance ran there with the documented runtime grants. The normal worker/scheduler were paused during deterministic queue/browser checks and are restored at handoff. The older M5 environment snapshot below is retained for context.

At the end of the implementation session, Compose project `weavdash` had app, PostgreSQL 16, Redis, ClamAV, worker and scheduler running; the app was available at `http://localhost:8000`. Recheck current state before relying on that snapshot. The separate synthetic `weavdash_m5_clean` database was retained. Runtime processes use `weavdash_app`; only controlled migrations/fixtures receive migration credentials.

Compose uses the shared `TRANSACTIONAL_MAIL_MAILER` override, defaulting to `array` for app/worker/scheduler. Both worker and scheduler were checked with local capture. Keep it for ordinary development; provider trials require an authorized inbox and transport configuration. No email was delivered externally during M5 verification.

Run Compose from the repository root with `--env-file src/.env`; npm/Composer/Artisan source commands belong under `src/`. See [README setup](../README.md), [M5 commands](m5-notification-contract.md#local-operations-and-transport) and [evidence commands](evidence/m5/README.md). Docker/package access may require the environment's ordinary escalation. Do not print private env configuration or use migration credentials in web/worker/scheduler.

For committed frontend files, formatting checks need an explicit path or the milestone base, for example `PRETTIER_BASE_REF=cbbf420 npm run format:check:changed` from `src/`. A clean working tree alone checks no changed files. Follow [AGENTS.md](../AGENTS.md), current feature contracts and the approved portal UI; do not overwrite reference assets/notices.

The later user-requested formatting follow-up standardizes four-space indentation, including Vue, and removes the older two-space Vue override. All 28 Vue files were passed through pinned Prettier; see [follow-up verification](evidence/m5/four-space-formatting/README.md). Preserve this updated rule when resuming work. Its broader browser run exposed an existing workspace-link target-size finding, retained as **M1-06** in the task register; the workspace template compiles identically before/after formatting.

## Preserved limits

No production release is approved. Required external email delivery, actual screen-reader/full browser zoom/native Safari, current M4/M5 Linux WebKit/full clean-setup coverage and current-candidate remote CI remain unverified. Production MFA, live storage/region/retention/export/deletion operations, recovery/monitoring/operator readiness, pilot validation and commercial decisions remain open under the register. Preserve private draft finance, explicit onboarding initiation, scoped relationships and approval before external outreach.
