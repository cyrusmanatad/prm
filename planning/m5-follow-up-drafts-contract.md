# M5-05 follow-up drafts and approval inbox

Status: implemented manual/private preparation path; local verification is recorded in the [evidence](evidence/m5/follow-up-drafts/README.md). M5 is incomplete. **Confirmed:** D15 requires explicit approval for external outreach, optional AI and private CRM; D25 requires transactional email independently of mailbox syncing. D28 assigns backend/necessary desktop work to Codex and small-screen acceptance to Claude.

## Recipient, content and sending boundary

**Implementation contract:** follow-ups belong to one verified editing workspace member and one active private pipeline entry. Another member, including a workspace administrator, cannot inspect or approve that person's drafts. Partnerships, public links and onboarding capabilities grant no access. Viewer and revoked membership contexts cannot read drafts. Current pilot entitlement must allow editing, approval and download; no assignment retains the existing development allowance.

The fully usable manual path is **write → save → review exact recipient/message → approve → download plain text → send using your own email client**. No AI, provider account or mailbox integration is needed. A manual draft requires one explicitly entered RFC email address, subject (240 characters maximum) and plain-text body (10,000 characters maximum). Multiple recipients, header newlines, HTML, attachments and automatic CRM interpolation are unsupported. The user chooses the recipient and content; a partnership never inserts or sends creator notes, rates or contacts. No follow-up outbox row, external send or delivery/receipt claim is produced in M5-05.

Approval is an explicit action on the displayed version, recipient and content, confirmed through the shared dialog. Saving any edit returns the draft to review and clears its approval. Concurrent/stale actions receive 409 with reload guidance. Discard also requires shared modal confirmation and retains history; discarded drafts cannot be edited, approved or downloaded.

The download endpoint requires authentication, verified email, current editing membership and entitlement, exact approved version, an unchanged active lead, and (for scheduled drafts) the current consent/preferences/schedule/run/rule basis. It returns a private, noncached text attachment with To/Subject/body. Downloads never send email. A previously downloaded file cannot be recalled; the user must review their email client before sending. An approval in history describes a recorded decision; it is not proof that the current download basis still permits handoff.

**Remaining required scope:** M5-06 now implements [logged-reply cancellation](m5-logged-reply-contract.md); M5-07 completes invalidation/reconciliation and concurrency acceptance across all changed approval bases. M5-08 must define and implement any application-sent approved outreach, follow-up review email recipient/consent/content, rate budgets and operator reconciliation before enabling that transport. M5-09 retains actual provider receipt and M5-10 broad recovery acceptance. The existing reminder/digest/lifecycle/invitation transactional paths remain required and unchanged. Manual handoff does not substitute for those email requirements or mark M5 complete.

## Durable scheduled preparation

Existing `follow_up_draft` schedules require explicit preference enablement, schedule consent, a current active pipeline version and a future instant. They prepare a generic editable plain-text seed with **no recipient**, private notes, contact information, workspace name, brand name, amounts or attachments. The owner must enter and review the recipient before approval. There is no automatic pitching.

`automation:dispatch` materializes durable runs. New `automation:drafts`, scheduled each minute with overlap protection, discovers up to 100 ready follow-up run routing IDs and dispatches `PrepareFollowUp` jobs containing scalar IDs only. `BusinessSchedules::execute` locks/rechecks current membership, entitlement, preferences, schedule and subject versions. Draft creation, audit and run completion commit together. Failures roll back together; a unique run constraint and completed-run check prevent replay duplicates. PostgreSQL routing reconstructs lost queued work. Reminder/digest consumers still exclude follow-up runs.

A changed/revoked schedule prevents obsolete preparation and blocks scheduled-draft approval/download. Re-enabling preferences alone does not restore consent. After an invalidated scheduled basis, write a fresh manual draft or create a new schedule. Reply cancellation is implemented under M5-06; general automatic marking of stale approvals remains M5-07.

## Persistence and UI

The additive `follow_up_drafts` table uses forced RLS with actor/workspace editing-membership policies, a tenant-composite lead foreign key, actor/workspace-composite run foreign key, unique run provenance, version checks and approval consistency constraints. No cross-tenant maintenance function projects content; `app_ready_follow_up_drafts` returns bounded routing IDs only and has no PUBLIC execute grant. Approved content stays private until the owner explicitly downloads it.

`/automation` adds ApprovalInbox and FollowUpComposer using FoundationShell, ContentSection, FormField, SelectField, ActionGroup, Button, validation focus and the shared ConfirmationDialog. ReminderSchedule's subject/date/consent presentation is extended for follow-ups rather than duplicated. The inbox shows the latest 50 drafts including discarded history; subject selectors show the newest 100 active leads. All records remain retained even when outside these bounded lists. Schedule withdrawal and preference withdrawal retain existing confirmation and cancellation behavior.

Rollback drops only draft storage and its routing function. It preserves pipeline, schedules/runs, consent history and existing notification records; rehearse only in the separate synthetic database `weavdash_m5_drafts_20261031`. Restoring the migration does not recreate removed drafts. Do not rehearse rollback on the shared development database.
