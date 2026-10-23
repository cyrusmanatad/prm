# Current Implementation Handoff

Updated: 2026-10-23. This is the current resume point; older milestone handoffs retain historical evidence and instructions for their original scope.

## Checkpoint and milestone state

- **UI-01 follow-up (2026-10-23):** shared native modal confirmations replace body/checkbox confirmations and immediate destructive/access-revoking submissions throughout the app, including both partnership portals. Required reasons/passwords, permissions and record versions remain in the original mutation handlers. Routine saves remain immediate. Pinned formatting/types, 26 component tests and build pass. All 34 affected desktop/mobile journeys have passing evidence across the broad run and the 12-test follow-up; the follow-up also closes the inherited M1-06 link target-size finding. Logs and inspected screenshots are retained in [confirmation evidence](evidence/confirmation-modals/README.md); this UI task does not close unfinished M5/M6 or inherited release work.

- Branch: `ui/confirmation-modals`; UI-01 follow-up accompanies this handoff, based on `925c0af`. The M5 checkpoint below is historical.
- Application checkpoint: `efd4fd6` — `feat: add durable partner invitation emails`. M4 was previously merged into the baseline. The subsequent documentation commit adds this handoff and the cross-milestone register.
- M1–M4 development is implemented; their release/manual/production gates remain open as recorded. M5's first notification slice is complete and locally verified; **M5 is incomplete**.
- **M6 preparation has started.** Its readiness contract and dependency register are prepared; operational implementation, drills and final acceptance remain pending. Required M5 work cannot be silently skipped when advancing to M6.
- The latest user request authorizes committing this work, progressing toward M6 where possible and retaining all unfinished/in-progress/deferred tasks. It does not authorize production deployment, purchases, mailbox access or real trial messages.

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

1. Inspect the current checkout and register before editing; preserve any new user/session work. Resume **M5-01/M5-02**: onboarding invitation recipients/send controls and lifecycle emails. Specify authorized recipients and safe return-link/session behavior before implementation. Reuse the existing durable outbox and current UI components.
2. Complete **M5-03–M5-08**: persistent scheduling/timezones/preferences/consent, reminder/digest delivery, follow-up drafts and approvals, reply cancellation, approval invalidation, rate/transport budgets and uncertain-delivery operator behavior. Optional mailbox syncing and AI are not required.
3. Complete **M5-09/M5-10** delivery/recovery acceptance with a separately authorized recipient-controlled trial. Existing local SMTP capture and historical account-mail trials do not prove current business-notification receipt.
4. Independent M6 preparation/rehearsals can proceed: start with **M6-01** isolated synthetic backup/restore planning/drill and **M6-03/M6-04** monitoring/runbooks, while keeping M5 dependencies explicit. Resolve hosting/region/recovery/pilot/operator decisions before dependent live operations.
5. Close inherited accessibility, MFA, storage/privacy/commercial and final candidate/UAT gates under their register IDs. Finish M6 acceptance only after required predecessor work and applicable release gates pass. Retain future M7–M9 and optional proposal rows for later implementation.

The register is the complete carry-forward index. This sequence prioritizes work; it does not replace or discard other open rows. If work stops mid-task, record the exact ID, files changed, last verified command/result and next step here and in the originating milestone status.

## Local environment and commands

UI-01 verification used Compose project `weavdash-m1`, with the rebuilt app running at `http://localhost:8000`. The older M5 environment snapshot below is retained for context.

At the end of the implementation session, Compose project `weavdash` had app, PostgreSQL 16, Redis, ClamAV, worker and scheduler running; the app was available at `http://localhost:8000`. Recheck current state before relying on that snapshot. The separate synthetic `weavdash_m5_clean` database was retained. Runtime processes use `weavdash_app`; only controlled migrations/fixtures receive migration credentials.

Compose uses the shared `TRANSACTIONAL_MAIL_MAILER` override, defaulting to `array` for app/worker/scheduler. Both worker and scheduler were checked with local capture. Keep it for ordinary development; provider trials require an authorized inbox and transport configuration. No email was delivered externally during M5 verification.

Run Compose from the repository root with `--env-file src/.env`; npm/Composer/Artisan source commands belong under `src/`. See [README setup](../README.md), [M5 commands](m5-notification-contract.md#local-operations-and-transport) and [evidence commands](evidence/m5/README.md). Docker/package access may require the environment's ordinary escalation. Do not print private env configuration or use migration credentials in web/worker/scheduler.

For committed frontend files, formatting checks need an explicit path or the milestone base, for example `PRETTIER_BASE_REF=cbbf420 npm run format:check:changed` from `src/`. A clean working tree alone checks no changed files. Follow [AGENTS.md](../AGENTS.md), current feature contracts and the approved portal UI; do not overwrite reference assets/notices.

The later user-requested formatting follow-up standardizes four-space indentation, including Vue, and removes the older two-space Vue override. All 28 Vue files were passed through pinned Prettier; see [follow-up verification](evidence/m5/four-space-formatting/README.md). Preserve this updated rule when resuming work. Its broader browser run exposed an existing workspace-link target-size finding, retained as **M1-06** in the task register; the workspace template compiles identically before/after formatting.

## Preserved limits

No production release is approved. Required external email delivery, actual screen-reader/full browser zoom/native Safari, current M4/M5 Linux WebKit/full clean-setup coverage and current-candidate remote CI remain unverified. Production MFA, live storage/region/retention/export/deletion operations, recovery/monitoring/operator readiness, pilot validation and commercial decisions remain open under the register. Preserve private draft finance, explicit onboarding initiation, scoped relationships and approval before external outreach.
