# M5 Durable Notifications: First Implementation Slice

Status: **first slice implemented; local automated gates passed; external delivery unverified**. This slice implements the PostgreSQL outbox and explicitly requested brand-to-partner invitation email. It does not complete M5. See [confirmed requirements](m5-email-notifications.md), [roadmap](implementation-roadmap.md) and [status](m5-status.md).

## Behavior and recipients

**Confirmed D15/D25:** supported transactional email works independently of mailbox syncing; external invitations require an explicit send action. Brand owners/admins may create an invitation link or select **Email this invitation to the recipient** before submitting. Existing API clients retain link-only behavior unless they send `send_email: true`. Validation, verified-recipient acceptance, seven-day expiry, throttling, workspace selection, explicit acceptance consent and revocation remain enforced.

**Implementation defaults:** this first slice supports one initial email per invitation. The recipient comes from the invitation record, never a queue argument. The plain-text message includes the absolute capability link built from the configured application URL, expiry, verified-recipient restriction and sharing boundary. Request host headers cannot change the email destination link. It omits private CRM/finance data and workspace names. Optional digest preferences do not apply to an explicitly requested invitation. Resending/reconciling uncertain delivery requires a later operator contract; no automatic resend control is provided.

The dialog uses `FormField` and the shared `Button`; history uses `ContentSection` and the current portal styles. History shows pending, sending, local capture, mail-server acceptance, cancellation or uncertainty. A captured local email is explicitly identified as local; SMTP acceptance does not claim mailbox receipt. Refresh the page to update status. Closing the dialog preserves the existing one-time copyable link.

## Transaction, queue and failure behavior

- The invitation, redacted audit events and encrypted notification intent commit in the same tenant transaction. A unique subject/kind key prevents duplicate intent. The database enforces a composite invitation/workspace target and forced RLS; only current owners/admins can inspect or update their workspace's intent. There is no runtime deletion policy.
- `notifications:dispatch`, scheduled every minute with an overlap lock, discovers up to 100 pending records using a narrow security-definer routine that returns only routing IDs. Repeated publication and Redis queue loss preserve the PostgreSQL intent. Queue jobs contain scalar actor, workspace and notification IDs; they contain no address or capability.
- Before reserving delivery, the worker establishes restricted tenant context, locks current membership, intent and invitation, and checks the initiating actor's current verified identity, owner/admin visibility, invitation state and expiry. Revocation/expiry/acceptance that wins those checks cancels delivery. Membership/role/verification loss cancels pending intent during reconciliation. These checks define the authorization boundary: mail already reserved/in flight cannot be recalled by a later revocation; revoked acceptance links remain unusable.
- The worker commits `sending` before contacting the transport. Concurrent or replayed jobs cannot repeat that send. SMTP acceptance becomes `sent`; the local array transport becomes `captured`. The capability ciphertext is removed on completion/cancellation/uncertainty.
- A transport exception becomes `uncertain`, with no automatic resend. A process crash after reservation becomes `uncertain` after five minutes. This conservatively includes a crash before the first SMTP write. Exceptions are not persisted verbatim because they may contain recipient, credential or link data. Stable Message-ID supports future reconciliation but does not guarantee SMTP deduplication.
- If membership disappears while mail is in flight, RLS can prevent the completion write; the stale reservation becomes uncertain during reconciliation. Queue errors before reservation leave pending intent available to a later dispatch. Unsupported transports leave intent pending until configuration is corrected. Throughput/backoff controls and recovery tooling remain later M5 tasks.

## Local operations and transport

Run from the repository root:

```sh
docker compose --env-file src/.env build app migrate acceptance worker scheduler
docker compose --env-file src/.env run --rm migrate
docker compose --env-file src/.env up -d app worker scheduler
docker compose --env-file src/.env exec -T scheduler php artisan notifications:dispatch
```

Compose uses the same `TRANSACTIONAL_MAIL_MAILER` override for app, worker and scheduler; it defaults to `array` for local capture. Existing `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME`, credentials and sender settings come from the shared env file. Set the override to `smtp` only for an authorized configured transport. SMTP timeout is 20 seconds, the job timeout 45 seconds and Redis retry-after 90 seconds. Business notification delivery permits SMTP and local/testing array capture; it refuses log/failover transports to keep capability links out of logs. Existing verification/reset paths retain their token, throttling and user-triggered behavior.

Local automated SMTP acceptance uses a one-message loopback sink inside the disposable acceptance container. It performs no external delivery. A separately authorized recipient-controlled provider delivery/link trial is still required before claiming externally working email.

## Remaining M5 work

Onboarding send/recipient controls and lifecycle notifications; persisted timezone/preferences/consent; reminders and digest; approval inbox and follow-up drafts; reply cancellation, approval invalidation, DST and rate budgets; operator reconciliation; crash/outage drills; and external delivery evidence remain open. AI stays optional and off the required path. Real invoice issuance, charging, unattended outreach and mailbox access remain outside this slice.
