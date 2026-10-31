# M5-04 Reminder and weekly digest/review email

Status: **implemented; backend/desktop locally verified; small-screen/provider acceptance pending**, based on `f0f6066`, 2026-10-31. [Evidence](evidence/m5/scheduled-delivery/README.md).

## Recipients, consent and cadence

**Confirmed D25/D28:** required application mail does not depend on mailbox syncing. Codex implements backend and necessary desktop controls; Claude retains administration and small-screen acceptance. No external trial or production deployment is included.

**Implementation defaults within the M5-03 named rules:** optional mail goes only to the schedule owner's currently verified account, in the schedule's workspace. There is no contact recipient, workspace-wide subscription, partner disclosure or external outreach. Reminder and weekly-digest preferences start off and require both an enabled preference and explicit schedule consent. Account verification/reset and explicit invitation/onboarding outcome mail keep their existing independent paths.

A `reminder` sends once at the explicitly chosen future instant for an open, versioned CRM reminder. The desktop Automation page offers the first 100 open reminders in due-date order and a browser-local date/time input, converted to an explicit UTC offset before submission. The saved instant remains fixed after timezone changes. Editing, snoozing, completing or deleting the subject makes its previous schedule obsolete; a new schedule requires fresh consent. No implicit backfill or auto-scheduling of existing CRM data occurs.

A `weekly_digest` follows the existing one-per-person/workspace ISO weekday, HH:MM and saved IANA timezone. It includes counts of incomplete reminders due at/before reservation and non-expired submitted onboarding forms awaiting review, queried through current tenant RLS. Zero-count reviews still send at the requested cadence. It contains no record titles, brand/contact/workspace names, private notes, financial amounts, submissions or attachments. Links use configured `APP_URL`, require sign-in/current workspace selection and show current records. Workspace ID identifies which workspace to select; links never select it implicitly or grant access. Counts are a reservation-time snapshot.

Existing DST behavior is retained: skip missing local times, use the first instant in repeated local times. Materialization coalesces missed weeks. If several runs accumulate during an extended delivery outage, newer weekly occurrences supersede older ready runs and pending email intent before reservation, avoiding a review-email backlog burst.

Changing preferences, timezone, schedule version or consent invalidates prior work. Turning a preference back on alone does not resume a schedule. Withdrawal disables the rule and cancels unreserved work; a send already reserved may still arrive. Follow-up draft/reply/approval notices remain M5-05–M5-08, where the corresponding records and delivery contract will be implemented. M5-04 never consumes `follow_up_draft` runs.

## Durable processing and isolation

1. Existing `automation:dispatch` reconstructs due occurrences and materializes durable runs.
2. New `automation:notify`, scheduled every minute with overlap protection, discovers up to 100 ready reminder/digest run routing IDs. `QueueScheduleNotice` carries only actor/workspace/schedule/run IDs. PostgreSQL ready state survives Redis loss.
3. `BusinessSchedules::execute` rechecks current membership, verification, entitlement, preference, consent, rule, schedule and subject versions with the established locks. The consumer appends outbox intent/audit and completes the run in that same transaction. Failure rolls both back; replay creates no duplicate. A unique run key further enforces one intent per occurrence.
4. Existing `notifications:dispatch` discovers pending outbox IDs. Before reservation the delivery worker locks membership, pilot advisory, preferences, schedule and subject, then the outbox row. The run's completed state and current versions are rechecked. This shared schedule-before-outbox order serializes consumption and consent withdrawal against reservation. The verified recipient and digest counts are read at reservation, without storing a recipient/content snapshot in the queue or outbox.
5. The send reservation commits before contacting mail. Only SMTP and local/testing array transports are accepted. `sent` means accepted by transport, `captured` means no external mail; neither is recipient receipt evidence. Ambiguous errors or a send stranded for five minutes become `uncertain`; jobs never automatically resend them. M5-08 operator reconciliation and numerical budgets remain open.

The additive migration adds `schedule_run_id` to the existing forced-RLS outbox, a composite `(org_id, actor_id, run_id)` reference and unique occurrence constraint. Scheduled notices are readable/updatable only by their actor with current editing membership, including against other same-workspace administrators. Existing invitation/lifecycle policies retain their behavior. Cross-workspace maintenance exposes routing IDs only; internal basis predicates have no PUBLIC/runtime execution grant. Capability contexts cannot see notices. Own history projects only ID, kind, state and creation time; no internal payload or arbitrary recipient is exposed.

Rollback removes only the new scheduled-notice rows/column/functions and restores preceding outbox policies/reconciliation; it preserves schedules, CRM, onboarding and other notification history. Rehearse only against the separate synthetic database.

## Desktop and operations

`/automation` reuses FoundationShell, AutomationPreferences, SavedSchedules, ContentSection, FormField, SelectField, shared Button, validation focus and shared confirmed withdrawal. New ReminderSchedule and NoticeHistory components contain their own presentation responsibilities; no new styling system is added. History distinguishes queued, reserved, transport-accepted, local capture, cancelled and uncertain states; it offers no unsafe retry button. Saving a schedule now truthfully describes future queueing instead of claiming delivery is unavailable.

Use existing local Compose array mail for development. Commands run from the repository root with `--env-file src/.env -p weavdash`; migration credentials belong only to migrate/controlled fixtures. Backend verification uses `weavdash_m5_delivery_20261031`. Required production/external provider receipt remains M5-09, full process/outage acceptance M5-10, and follow-up/approval/budget/operator work remains M5-05–M5-08. Small-screen acceptance, administration, UI-05 and inherited release gates remain open.
