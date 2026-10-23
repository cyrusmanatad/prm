# M5 Durable Automation and Notifications Status

Status: **first slice complete; local automated gates passed; milestone incomplete**. Prepared on `m5/durable-automation` on 2026-10-23, preserving the existing D25 planning changes.

The [first-slice contract](m5-notification-contract.md) covers the durable PostgreSQL notification outbox, Redis dispatcher/worker, scheduler, explicit partner invitation email, delivery/cancellation feedback and conservative handling of ambiguous sends. See [email requirements](m5-email-notifications.md) for the full inventory.

## Verification

The full PostgreSQL acceptance suite passed **46 tests / 987 assertions**, integration passed **27 tests / 503 assertions**, and unit/feature regressions passed **29 tests / 256 assertions**. A separate fresh database passed migration, M5 rollback/reapply, catalog privilege checks, and **10 notification acceptance tests / 129 assertions**. These include actual Redis queue loss/recovery, loopback SMTP, worker SIGKILL during SMTP DATA, reclaimed reservations, cancellation, replay, current authorization, encryption and forged-host link protection.

Frontend formatting, type checking, **21 Vitest tests**, production build and PHP Pint passed. **8 desktop/mobile browser journeys passed; 14 Axe scans reported zero violations.** Retained screenshots and exact commands are recorded in [M5 evidence](evidence/m5/README.md). Shared `FormField`, `Button` and `ContentSection` preserve the current portal design. No provider message or production deployment occurred; external delivery remains unverified. Native Safari/WebKit, manual screen-reader/full zoom review and prior release gates remain open. The standalone single-user schema checker was extended; real PostgreSQL fresh-schema/catalog checks supplied the local schema evidence because host PostgreSQL binaries are absent.

## Next tasks and remaining gates

The [remaining-task register](remaining-tasks.md#m5-required-implementation-and-delivery-work) tracks these under **M5-01–M5-10**, with dependencies and completion evidence. The first slice is committed as `efd4fd6`. See the [current handoff](implementation-handoff.md) and [M6 preparation](m6-status.md); M6 acceptance does not waive the remaining M5 work.

1. Add onboarding invitation recipient/send controls and submitted/correction/accepted notifications with scoped recipients and safe return links.
2. Implement persisted scheduling, timezone/preferences/consent, reminders and digest.
3. Add approval inbox, follow-up drafts, logged-reply cancellation and approval invalidation, plus rate budgets and optional AI off-path behavior.
4. Complete crash/restart/outage, DST, operator reconciliation and external recipient-controlled delivery acceptance. Preserve M1–M4 release gates and the mandatory UI standards.

M5 is not complete until its required notification inventory and automation gates pass. The first slice does not settle precise future lifecycle recipients, digest cadence or uncertain-delivery operator permissions.

## Four-space formatting follow-up

The user's subsequent request changes Prettier to four-space indentation for Vue as well as other supported frontend files. The Vue-only two-space override was removed, all 28 Vue files were formatted with pinned Prettier and contributor guidance was updated. See the [follow-up validation](evidence/m5/four-space-formatting/README.md). Existing milestone tasks and release gates remain tracked in [remaining tasks](remaining-tasks.md).

## Required app-wide confirmation follow-up

**Confirmed UI-01:** shared native modal confirmations now cover destructive actions and access revocations across milestones, including both partnership portals and invitation email cancellation. See [implementation and verification](evidence/confirmation-modals/README.md) for pinned formatting/types/build, 26 component tests, affected desktop/mobile journeys, Axe checks and inspected screenshots. The follow-up also closes M1-06's workspace-link target size finding. M5's remaining implementation and inherited release gates stay open in the [register](remaining-tasks.md).
