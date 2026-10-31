# M5 Email Notification Requirements

Status: **scope clarified; first implementation slice available; M5 incomplete**. See D25 in [decisions](decisions.md), the [M5 roadmap](implementation-roadmap.md), [status](m5-status.md) and [first-slice contract](m5-notification-contract.md). This document records the notification requirement and remaining gaps; actual results are in [M5 evidence](evidence/m5/README.md).

## Confirmed boundary

**Confirmed D25:** Gmail/Outlook mailbox syncing is a future, optional enhancement. Email notifications required by supported product workflows must work without mailbox syncing. M5 must close the existing notification gaps as well as implement its new scheduled notifications; approval records or in-app indicators alone do not satisfy email delivery requirements.

Use application-sent transactional mail through a configured mail transport. Reading users' mailboxes, importing conversation threads, automatic inbound reply detection, and acting as a full email client are outside this requirement. Logged replies can cancel follow-ups without mailbox integration. External outreach still follows D15's approval requirement; ordinary account verification/reset requests keep their existing user-triggered behavior. Draft financial records remain private and unissued under M4.

## Current implementation audit

| Workflow | Current behavior | M5 requirement / proposed detail |
|---|---|---|
| Account verification and password reset | Laravel notifications are wired; M1 records an external SMTP trial. Default local Compose uses the array transport. | Preserve working delivery and link validation; include regression coverage. Historical trial evidence does not prove current environment delivery. |
| Brand–partner invitation | First M5 slice adds explicit email selection, transactionally persisted encrypted intent, queued mail and delivery status alongside the copyable link. | Local queue/SMTP verification is implemented; separately authorized provider delivery remains pending. Preserve existing recipient and acceptance restrictions. |
| Creator onboarding invitation | M5-01 adds optional email when starting onboarding or replacing an invitation, with an explicit validated recipient, encrypted durable intent and delivery history. | [Recipient/access contract](m5-onboarding-email-contract.md) and [local verification](evidence/m5/onboarding-email/README.md). Provider receipt remains M5-09; existing single-use/session/revocation rules remain enforced. |
| Onboarding submitted, correction requested, accepted | M5-02 implements opt-in alerts to the initiating writer and explicitly addressed outcome email, with fresh single-use return links. | [Recipient/access contract](m5-onboarding-lifecycle-contract.md), [local backend/desktop evidence](evidence/m5/onboarding-lifecycle/README.md). Small-screen checks belong to Claude; provider receipt remains M5-09. |
| Reminders, follow-up review, digests | M5-04 consumes persistent reminder/weekly-digest runs into the outbox. Current verified account receives one-shot reminder notices and timezone-local weekly due-reminder/onboarding-review counts; explicit opt-in, reservation rechecks and private history are implemented. | [Delivery contract](m5-scheduled-delivery-contract.md), [local backend/desktop evidence](evidence/m5/scheduled-delivery/README.md). Small-screen/provider acceptance remains open. Follow-up draft/approval notifications and approved outreach require M5-05–M5-08. |

**Implementation contracts** above now define recipients, consent and cadence for invitations, lifecycle alerts, reminders and weekly review. **Proposal:** future follow-up review events extend this inventory. The broad requirement is confirmed; remaining follow-up recipients, approval/send controls and uncertain-delivery operator actions must be specified in their M5 implementation contracts. Account recovery messages must not be suppressed by optional digest preferences. No message should expose creator-private CRM or financial data merely because a partnership exists.

## Delivery acceptance

- Persist business notification intent transactionally with its event; dispatch through the M5 outbox/queue design. Define how the existing account notification paths fit without weakening their token or throttling guarantees.
- Verify message content, intended recipient, absolute links, expiry/revocation, and tenant boundaries. Keep capability tokens protected in queued payloads and out of logs and audit events.
- Make app and worker mail configuration consistent. The current external-mail trial override enables SMTP only on the app service; that is insufficient evidence for future queued business mail.
- Cover retries, worker recovery, cancellation, and delivery failures. A successful queue submission is not proof of receipt. SMTP alone cannot guarantee exactly-once delivery after an ambiguous transport failure; define reconciliation and retry behavior instead of claiming an unsupported guarantee.
- Use automated mail fakes/capture to validate all required events and a separately authorized recipient-controlled delivery check to verify the configured transport, queued path, and links. Do not send trial mail to real contacts during development merely to validate the implementation.
- Record actual delivery evidence and any configuration blockers before declaring email notifications complete. Gmail/Outlook syncing is not an acceptance dependency.

The UI must reuse the current portal layouts/components, provide clear send/pending/failure feedback where relevant, and pass the repository's Prettier, type, build, interaction, and desktop/mobile review gates.
