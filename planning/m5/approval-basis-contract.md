# M5-07 follow-up approval invalidation

Status: implementation and local acceptance tracked in [verification](approval-basis-evidence.md). **Confirmed D15/D25/D28:** preserve private CRM, explicit outreach approval, the manual path and the existing division of work. M5 remains incomplete; no follow-up transport or provider send is introduced.

## Approval lifetime

Approval covers one exact recipient, subject, body and draft version, the active lead version, current editing membership, verified identity and current entitlement. Scheduled drafts additionally require the original completed run, schedule/rule/preference versions and current consent. Approval and plain-text download retain their current locked checks; stale requests fail rather than silently approving a replacement version.

Saving recipient/content edits returns the draft to review and clears approval. A database content trigger also clears retained approval and increments the version if direct runtime SQL changes approved content or provenance. It does not authorize arbitrary SQL clients to approve messages.

Changes to the lead, schedule/run basis, preferences, membership, verified identity or entitlement invalidate existing approvals in the same transaction. The message remains in **Discarded** history with cleared approval fields and an incremented version. Write a fresh manual draft and explicitly review/approve it; restoring consent, membership or identity never restores the old approval. Preference changes affect scheduled drafts, not unrelated manual drafts. Lead changes cover every private owner for that lead without projecting their recipients or messages. Unrelated workspaces and leads retain their approvals.

Professional entitlement changes preserve the existing **cancelled** terminal state and renewal behavior. Explicit logged replies preserve their existing cancellation of draft/approved work, schedules and ready runs. Ordinary activities still do not imply a reply or cancel schedules, but their lead-version increment now durably invalidates an existing approval, which previously already failed download's version check. Existing discarded history stays discarded.

## Reconciliation and races

Time passing has no write event. Every-minute `automation:drafts` now also calls `app_reconcile_follow_up_approvals()`, which discovers up to 100 invalid approvals per invocation, rechecks under the workspace lock, clears them and appends one `follow_up.invalidated` audit event per affected draft. PostgreSQL retains the source records across queue/scheduler outages; repeated calls do not increment versions or duplicate audits after invalidation. The event is attributed to the draft owner and contains identifiers only. Reconciliation returns no content, identifiers or counts to the caller.

Membership locks precede the workspace `pilot:` advisory lock. Lead changes, schedule/preference edits, approval and handoff acquire that advisory lock before preferences, schedules, leads and drafts as applicable. Trigger invalidation acquires workspace locks in organization order before updating drafts. Lead/schedule/run triggers finish at transaction commit, allowing explicit reply cancellation to keep its terminal semantics. Stored schedule/run changes still invalidate approvals even when the fields are restored in that transaction. Membership/identity/preference/entitlement changes invalidate immediately.

A basis change that locks first blocks a stale handoff; a handoff that locks first may finish before invalidation commits. Already downloaded files cannot be recalled. Current grants, entitlement and exact-version checks remain required at any future M5-08 external-send reservation; historical approval alone must never authorize delivery.

## Storage and rollback

The additive migration installs narrow, fixed-search-path helpers/triggers and a partial approval index. Existing forced RLS/read policies and recipient/content ownership remain unchanged. Runtime/PUBLIC cannot execute the internal predicate or trigger functions; runtime can invoke only the content-free reconciliation entry point. No existing content is deleted or rewritten by migration installation.

Rollback removes these helpers/triggers/index only. It does not restore invalidated approvals or delete retained draft/audit history. Rehearse rollback only in the separate synthetic database described in verification, never in a shared/live database.

## Remaining gates

M5-08 still needs the operator permissions/reconciliation, approved application-sent outreach, opt-in review notices and transport/rate-budget contract and implementation. Provider receipt is M5-09; broader outage/recovery acceptance is M5-10. Administration, broader small-screen/manual accessibility/UI-05 and inherited release gates remain separate. This slice's narrow Chromium mobile regression does not close those reviews.
