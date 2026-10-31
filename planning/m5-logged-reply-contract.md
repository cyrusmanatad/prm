# M5-06 logged-reply cancellation

Status: implementation and local verification tracked in the [evidence](evidence/m5/logged-reply/README.md). M5 remains incomplete. **Confirmed:** D15 keeps CRM private and requires outreach approval; D25 makes mailbox integration optional; D28 retains Claude's small-screen and administration scope.

## Explicit reply and cancellation boundary

**Implementation contract:** an editing workspace member selects **Reply received** in the existing lead activity form and supplies details. The shared confirmation identifies the lead, cancellation consequence and previously downloaded-file limitation. Cancel has initial focus; Escape restores the trigger. Logging a note, email, call or chat does not imply a reply. No mailbox is read and no message is sent.

A valid exact-version reply appends activity/audit history and increments the lead version once. In the same transaction it clears `next_action_due`, disables existing enabled `follow_up_draft` schedules for that lead, clears their next due dates, increments their versions, cancels their ready runs, and marks existing draft/approved follow-ups **cancelled** with cleared approvals and incremented versions. Completed runs retain completion/provenance; discarded/cancelled drafts retain history. Stale requests fail with 409; invalid/denied/rolled-back replies have no cancellation effects.

Cancellation covers every draft/schedule owner for the lead in the selected workspace. It does not expose another owner's recipient, message, schedule or cancellation count. Private RLS read policies are unchanged. A fixed-search-path, write-only security-definer trigger performs the cross-owner cancellation only following an authorized reply insertion; runtime/PUBLIC have no direct execute grant. Foreign workspaces, viewers, revoked memberships and onboarding/public capability contexts gain no authority.

**Implementation scope:** clearing the lead's next-action date cancels its implicit due action. Separate reminder records, including linked reminders, stay active: their titles do not establish that they are obsolete outreach, and completing them would falsely claim the task was done. Other leads, weekly reviews, invitations and lifecycle notifications remain unchanged. This boundary is stated before confirmation. M5-08 must preserve reply cancellation when adding an actual follow-up transport; no such outbox intent exists yet.

## Replay, concurrency and fresh work

Reply, preparation, schedule save and draft approval/edit/download share the existing workspace `pilot:` transaction advisory lock before taking lead/draft locks. The reply transaction retains it through cancellation. A queued/replayed preparation that runs afterward finds a disabled schedule/cancelled run and produces nothing. Preparation that commits first can create a draft, which the reply then cancels. Approval/download that reserves first can finish before reply cancellation commits; a downloaded file cannot be recalled. Downloads that wait behind the reply fail their version/state checks. All external sending remains manual and requires the user's email-client review.

Cancelled drafts cannot be edited, approved or downloaded even with their new version. A stale open composer receives conflict feedback. Users may explicitly create a fresh manual draft or consent to a new schedule version after a reply; historical replies are a cutoff for existing work, not a permanent ban on collaboration. Ordinary edits cannot revive a cancelled draft.

## Migration and retained gates

The additive migration extends activity/draft state constraints and installs the trigger; it backfills no replies and deletes no records. Rollback removes the trigger/function and converts cancelled drafts to discarded while retaining reply activity and cancellation effects. It intentionally retains the allowed `reply` activity kind to preserve history; rollback does not re-enable schedules, restore approvals or dates. Rehearse only in the separate synthetic database documented in evidence.

M5-07 retains general approval-basis invalidation/reconciliation and broader race acceptance. M5-08 retains app-sent approved outreach/review notices, budgets and operator reconciliation; M5-09 provider receipt and M5-10 broader recovery remain open. This slice does not close small-screen, UI-05, administration or inherited release gates.
