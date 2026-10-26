# M5-02 Onboarding lifecycle email

Status: **implemented; backend and desktop verification recorded; small-screen/provider acceptance pending**, based on `4f90c3e`, 2026-10-26. See [evidence and commands](evidence/m5/onboarding-lifecycle/README.md).

## Session scope

**Confirmed:** prioritize M5 backend delivery and necessary desktop controls. Administration and small-screen UI testing/optimization belong to Claude. UI-05 visual deviation acceptance remains open and is carried forward; this session authorizes continuing backend development without declaring that gate complete. No external messages or production operations are authorized.

## Recipient and access defaults

**Implementation defaults under D15/D25:** all lifecycle email is transactional and explicitly selected. When creating or replacing an invitation, a verified writer may select **Email me when this form is submitted**. This stores that actor's ID, never a public form-supplied recipient. Replacement resets this choice unless explicitly selected again. A submission queues one notice for that currently verified, authorized writer; existing requests are not backfilled. No workspace-wide broadcast or recipient inferred from private CRM is introduced.

Correction and acceptance retain their existing versioned state transitions. A reviewer may separately select **Email this review outcome**, entering a recipient address explicitly for this action. No submitted business address is silently trusted as a delivery destination. Messages contain only the workflow outcome and a canonical application link; private names, notes, submitted fields, correction text, documents and finance remain on the protected page. Optional digest preferences remain M5-03/M5-04.

Submitted notices link to the authenticated review page; the user must select the authorized workspace. They grant no new access. Correction/acceptance notices contain a new single-use bearer return link, independent of the consumed original invitation. GET does not consume it. Explicit opening creates the existing browser-bound session for at most 24 hours and never beyond the request's seven-day invitation expiry. Thus a return link works after a previous 24-hour session expires while the request is still valid. If the request itself expired, replace the invitation before requesting outcome email; no email silently extends access. Accepted forms remain read-only.

An unused return link requires the same request generation, exact outcome version/status, current verified writer authorization and unexpired request/link. Later edits, another outcome, replacement or revocation invalidate it. Replacement/revocation retains existing session invalidation rules. Once opened, the session follows the existing capability lifetime; revoking/replacing the request ends it. Email recipients are bearer-capability holders, not new account identities, consistent with M5-01.

## Durable processing and isolation

Event, outbox intent and any return-token hash commit atomically. Submission replay does not create another notice; notice identity includes request, kind and event version. Public submission uses a narrowly scoped PostgreSQL function which can only enqueue the stored, authorized reviewer for the currently accessible submitted form. Capability callers cannot read the outbox or return-token hashes. All new tables enforce RLS, and workers retain restricted runtime credentials.

Reuse scalar-ID Redis jobs and the durable dispatcher. Workers recheck current writer verification/membership, request generation, version/status and expiry before reserving delivery. Obsolete pending intent is cancelled and encrypted payload discarded. Existing conservative sending/uncertain handling remains: no automatic replay after sending begins; capture/server acceptance never claims mailbox receipt. No automatic resend is added.

## Implemented API and schema

`POST /onboarding-requests` and `POST /onboarding-requests/{id}/renew` accept optional boolean `notify_submission`, default false, independently of invitation `send_email`. The current actor is the only possible alert recipient. `correct` and `accept` accept the existing validated `send_email`/`recipient_email` pair; omission keeps the original link-only review behavior. Stale versions, denied permissions and expired invitations cannot persist outcome-email intent. Public submit/save accepts no routing preference or recipient fields.

Migration `2026_10_26_000001` adds `onboarding_requests.submission_notify_user_id`, `notification_outbox.onboarding_version` and forced-RLS `onboarding_return_links`. Partial unique indexes separate invitation-generation deduplication from lifecycle-event deduplication. A composite foreign key binds each return link to the same workspace, request and notification. Only a narrow security-definer exchange can consume a return token. `POST /onboarding/session` retains its existing throttled token-in-body contract and tries the original invitation before the new return-link exchange. No public route, GET token query, automatic workspace selection or private-data projection is added.

Rollback removes lifecycle notification history, return links and opt-in configuration, preserving onboarding requests, submissions, documents and invitation history. The disposable rehearsal verifies request counts survive; it does not authorize live rollback or data cleanup.

## Verification and handoff

Required evidence: real PostgreSQL cross-tenant/capability/role denial; opt-in and address validation; encrypted payload and minimal message content; atomic rollback and idempotent submission; consumed/expired/replaced/revoked return links; stale/out-of-order events; Redis loss/replay and uncertain outcomes; migration rollback/reapply; existing invitation regressions. UI reuses shared fields, buttons, sections and the existing confirmation flows. Run formatting/types/build/component tests and desktop browser/keyboard/Axe/screenshot review. Small-screen checks are explicitly delegated to Claude and remain unverified here. Provider delivery remains M5-09; M5 and inherited release gates stay open.
