# M5-01 Onboarding Invitation Email

Status: **implemented; local automated gates passed; provider delivery unverified** on `m5/onboarding-invitation-email`, based on `ce2d9bd`. This slice closes the invitation gap in [M5 email requirements](m5-email-notifications.md); lifecycle notices remain M5-02. See [verification and inspected screenshots](evidence/m5/onboarding-email/README.md).

## Recipient and access contract

**Implementation defaults under D15/D25:** current verified onboarding writers (owner, admin, manager and member) may explicitly request one email when starting onboarding or replacing its invitation. Email is unchecked by default. A valid, explicitly entered recipient address is required when selected; no CRM contact or submitted business address is silently selected. Omitting `send_email` preserves link-only API behavior. The address and capability URL are encrypted together in the durable outbox and removed after completion, cancellation or uncertainty. Audit and queue payloads contain identifiers only.

The recipient is a delivery destination, not a new identity restriction: the existing private bearer capability remains single-use, expires after seven days, and creates a browser-bound session lasting up to 24 hours. Mail states this boundary and asks recipients to keep the link private. The landing page requires an explicit Open invitation action, so a GET by an email scanner does not consume access. Messages omit lead labels, private CRM, submitted fields, files and finance. Links use the configured application origin, never an incoming Host header.

Replacement retains the required modal confirmation, version check and session invalidation. Each generation can request one email; a replacement needs fresh explicit send selection and recipient entry. There is no automatic resend. A replacement invalidates the old token even if its email was delivered. Revocation, replacement, expiry or consumption before the worker reserves delivery cancels obsolete intent. A later change cannot recall an in-flight message; the capability checks still prevent obsolete access.

## Durable delivery and UI

Reuse M5's outbox, scalar-ID Redis jobs, dispatcher, transport allowlist and conservative uncertain-send behavior. Persist invitation and intent atomically. Database constraints enforce the onboarding request/workspace pair and generation uniqueness; forced RLS restricts onboarding intent to current writers without widening partner-invitation access. The worker locks current membership and invitation, rechecks verification and the current token hash, then commits its reservation before mail transport.

Reuse shared fields/buttons and existing layouts. Initial sending is selected in the lead drawer; replacement sending is selected on the onboarding review screen. Delivery history distinguishes pending, sending, local capture, mail-server acceptance, cancellation and uncertainty. Refresh to update. No UI claims mailbox receipt.

## Acceptance

Verify recipient validation, opt-in defaults, atomic rollback, encrypted payloads, canonical links, generation uniqueness, stale replacements, revocation/expiry/consumption, current role/membership/verification, denied cross-tenant reads/writes and capability isolation against real PostgreSQL. Exercise the queued path with local mail capture, replay and uncertain outcomes; preserve partner notification regressions. Check migration rollback/reapply with synthetic data. Run pinned formatting, types, build, component and affected desktop/mobile browser tests; inspect screenshots and record evidence.

External provider delivery remains M5-09 and requires a separately authorized recipient-controlled trial. M5-02–M5-10 and inherited M6/release gates remain open.
