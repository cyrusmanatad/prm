# M5 Durable Automation and Notifications Status

Status: **partner and onboarding invitation slices complete; local automated gates passed; milestone incomplete**. The original slice was prepared on `m5/durable-automation` on 2026-10-23. M5-01 follows on `m5/onboarding-invitation-email`, based on `ce2d9bd`.

**UI review (2026-10-25):** [UI-07](remaining-tasks.md) requires queued/requested invitation copy instead of claiming “sent” before delivery, plus current global toast acceptance. UI-02 now has [passing repair evidence](evidence/pipeline-repair-2026-10-25/README.md); UI-06 now has [passing CRM repair evidence](evidence/crm-repair-2026-10-25/README.md); finish UI-07 and broader UI-05 reference/deviation acceptance before resuming M5-02; invitation outbox implementation and M5-09 provider acceptance retain their existing disposition. See the [latest UI review](evidence/ui-plan-review-2026-10-25/README.md).

The [first-slice contract](m5-notification-contract.md) covers the durable PostgreSQL notification outbox, Redis dispatcher/worker, scheduler, explicit partner invitation email, delivery/cancellation feedback and conservative handling of ambiguous sends. See [email requirements](m5-email-notifications.md) for the full inventory.

## M5-01 onboarding invitation email

Explicit optional email now accompanies Start onboarding and confirmed replacement invitations, with a required entered recipient and default link-only behavior. Encrypted durable intent is tied to the request/workspace and token generation. Current writer/verification checks, expiry, consumption, replacement and revocation prevent obsolete sends. Delivery history distinguishes local capture, mail-server acceptance and uncertainty. The existing single-use link, browser session and replacement confirmation remain intact; private CRM/form data is excluded from messages. See the [recipient/access contract](m5-onboarding-email-contract.md).

Verification on the working tree based on `ce2d9bd`: **53 PostgreSQL acceptance tests / 1,142 assertions** in a fresh synthetic database, **27 integration tests / 503 assertions**, **29 identity tests / 256 assertions**, 26 component tests, formatting/types/build/Pint/source scan, migration rollback/reapply and catalog checks passed. All **8 affected Chromium desktop/mobile journeys** pass across the broad run and corrected two-test follow-up, with clean embedded Axe checks and inspected screenshots. The first full fresh-database run needed the documented runtime grants; the rerun passed. The browser correction matched the existing revoked-link error message. See [commands and evidence](evidence/m5/onboarding-email/README.md).

Provider delivery remains M5-09; no real email was sent. M5-02 lifecycle notifications and M5-03–M5-10 remain open, as do inherited release reviews.

## Original first-slice verification

The full PostgreSQL acceptance suite passed **46 tests / 987 assertions**, integration passed **27 tests / 503 assertions**, and unit/feature regressions passed **29 tests / 256 assertions**. A separate fresh database passed migration, M5 rollback/reapply, catalog privilege checks, and **10 notification acceptance tests / 129 assertions**. These include actual Redis queue loss/recovery, loopback SMTP, worker SIGKILL during SMTP DATA, reclaimed reservations, cancellation, replay, current authorization, encryption and forged-host link protection.

Frontend formatting, type checking, **21 Vitest tests**, production build and PHP Pint passed. **8 desktop/mobile browser journeys passed; 14 Axe scans reported zero violations.** Retained screenshots and exact commands are recorded in [M5 evidence](evidence/m5/README.md). Shared `FormField`, `Button` and `ContentSection` preserve the current portal design. No provider message or production deployment occurred; external delivery remains unverified. Native Safari/WebKit, manual screen-reader/full zoom review and prior release gates remain open. The standalone single-user schema checker was extended; real PostgreSQL fresh-schema/catalog checks supplied the local schema evidence because host PostgreSQL binaries are absent.

## Next tasks and remaining gates

The [remaining-task register](remaining-tasks.md#m5-required-implementation-and-delivery-work) tracks these under **M5-01–M5-10**, with dependencies and completion evidence. The first slice is committed as `efd4fd6`. See the [current handoff](implementation-handoff.md) and [M6 preparation](m6-status.md); M6 acceptance does not waive the remaining M5 work.

1. Specify and implement M5-02 submitted/correction/accepted notifications with scoped recipients and usable return links after the existing 24-hour session expires. M5-01 invitation recipient/send controls are complete locally.
2. Implement persisted scheduling, timezone/preferences/consent, reminders and digest.
3. Add approval inbox, follow-up drafts, logged-reply cancellation and approval invalidation, plus rate budgets and optional AI off-path behavior.
4. Complete crash/restart/outage, DST, operator reconciliation and external recipient-controlled delivery acceptance. Preserve M1–M4 release gates and the mandatory UI standards.

M5 is not complete until its required notification inventory and automation gates pass. The first slice does not settle precise future lifecycle recipients, digest cadence or uncertain-delivery operator permissions.

## Four-space formatting follow-up

The user's subsequent request changes Prettier to four-space indentation for Vue as well as other supported frontend files. The Vue-only two-space override was removed, all 28 Vue files were formatted with pinned Prettier and contributor guidance was updated. See the [follow-up validation](evidence/m5/four-space-formatting/README.md). Existing milestone tasks and release gates remain tracked in [remaining tasks](remaining-tasks.md).

## Required app-wide confirmation follow-up

**Confirmed UI-01:** shared native modal confirmations now cover destructive actions and access revocations across milestones, including both partnership portals and invitation email cancellation. See [implementation and verification](evidence/confirmation-modals/README.md) for pinned formatting/types/build, 26 component tests, affected desktop/mobile journeys, Axe checks and inspected screenshots. The follow-up also closes M1-06's workspace-link target size finding. M5's remaining implementation and inherited release gates stay open in the [register](remaining-tasks.md).
