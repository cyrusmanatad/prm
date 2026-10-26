# M5 Durable Automation and Notifications Status

**Current session (2026-10-26, D28):** M5-03 scheduling foundation is implemented and backend/desktop verified in the working tree based on `258136f`. See the [schedules/consent contract](m5-schedules-contract.md) and [evidence/screenshots](evidence/m5/schedules/README.md). Named versioned rules, per-person/workspace IANA timezone and opt-in preferences, append-only consent, durable run reconstruction, atomic execution and current membership/verification/entitlement/subject checks are in place. **Next backend task: M5-04 reminder and digest/review delivery.** No scheduled email consumer or external delivery is claimed. M5-02 lifecycle email remains locally verified under its [contract](m5-onboarding-lifecycle-contract.md). Claude owns administration and small-screen verification/refinement. UI-05, small-screen/provider and inherited release gates remain open; M5 is incomplete.

**Confirmed session scope (2026-10-25):** the user held administrator implementation for this session and then authorized continuing development. UI-07 notification feedback was completed under that session scope while ADM-01–ADM-07 remain unimplemented and required; this does not waive D27's launch priority or advance M5/M6 acceptance.

Status: **invitation, onboarding lifecycle and scheduling foundation slices locally verified; milestone incomplete**. The original slice was prepared on `m5/durable-automation` on 2026-10-23. M5-01 follows on `m5/onboarding-invitation-email`, based on `ce2d9bd`.

**UI-07 (2026-10-25):** queued-email copy, repeated/initial/history feedback, modal notification accessibility, reduced-motion dismissal and persistent clipboard recovery are implemented and locally verified. Formatting/types/build, 51 component tests, PHP 29/256 and all 22 distinct affected desktop/mobile journeys have passing evidence across the broad run and two-test accessibility follow-up. See [notification feedback evidence](evidence/notification-feedback-2026-10-25/README.md). Administration remains held for this session only; ADM-01–ADM-07 are still required and unimplemented. Broader UI-05 reference/deviation acceptance and M5-02 remain open.

**Confirmed D27 priority (2026-10-25):** implement required platform administration ADM-01–ADM-07 before further milestone advancement. ADM-01 authorization/MFA/bootstrap is next; see the [administration contract](platform-administration-contract.md). After this package, return to broader UI-05 acceptance and M5-02; UI-07 is locally verified. M5 notification/automation requirements remain open; this order does not waive them.

**UI review follow-up (2026-10-25):** [UI-07](remaining-tasks.md) now has scoped local passing evidence for queued-email copy and global feedback. UI-02 now has [passing repair evidence](evidence/pipeline-repair-2026-10-25/README.md); UI-06 now has [passing CRM repair evidence](evidence/crm-repair-2026-10-25/README.md); finish broader UI-05 reference/deviation acceptance before resuming M5-02; invitation outbox implementation and M5-09 provider acceptance retain their existing disposition. See the [latest UI review](evidence/ui-plan-review-2026-10-25/README.md).

The [first-slice contract](m5-notification-contract.md) covers the durable PostgreSQL notification outbox, Redis dispatcher/worker, scheduler, explicit partner invitation email, delivery/cancellation feedback and conservative handling of ambiguous sends. See [email requirements](m5-email-notifications.md) for the full inventory.

## M5-01 onboarding invitation email

Explicit optional email now accompanies Start onboarding and confirmed replacement invitations, with a required entered recipient and default link-only behavior. Encrypted durable intent is tied to the request/workspace and token generation. Current writer/verification checks, expiry, consumption, replacement and revocation prevent obsolete sends. Delivery history distinguishes local capture, mail-server acceptance and uncertainty. The existing single-use link, browser session and replacement confirmation remain intact; private CRM/form data is excluded from messages. See the [recipient/access contract](m5-onboarding-email-contract.md).

Verification on the working tree based on `ce2d9bd`: **53 PostgreSQL acceptance tests / 1,142 assertions** in a fresh synthetic database, **27 integration tests / 503 assertions**, **29 identity tests / 256 assertions**, 26 component tests, formatting/types/build/Pint/source scan, migration rollback/reapply and catalog checks passed. All **8 affected Chromium desktop/mobile journeys** pass across the broad run and corrected two-test follow-up, with clean embedded Axe checks and inspected screenshots. The first full fresh-database run needed the documented runtime grants; the rerun passed. The browser correction matched the existing revoked-link error message. See [commands and evidence](evidence/m5/onboarding-email/README.md).

Provider delivery remains M5-09; no real email was sent. M5-02/M5-03 backend/desktop verification is now recorded above; their small-screen gates, M5-04–M5-10 and inherited release reviews remain open.

## Original first-slice verification

The full PostgreSQL acceptance suite passed **46 tests / 987 assertions**, integration passed **27 tests / 503 assertions**, and unit/feature regressions passed **29 tests / 256 assertions**. A separate fresh database passed migration, M5 rollback/reapply, catalog privilege checks, and **10 notification acceptance tests / 129 assertions**. These include actual Redis queue loss/recovery, loopback SMTP, worker SIGKILL during SMTP DATA, reclaimed reservations, cancellation, replay, current authorization, encryption and forged-host link protection.

Frontend formatting, type checking, **21 Vitest tests**, production build and PHP Pint passed. **8 desktop/mobile browser journeys passed; 14 Axe scans reported zero violations.** Retained screenshots and exact commands are recorded in [M5 evidence](evidence/m5/README.md). Shared `FormField`, `Button` and `ContentSection` preserve the current portal design. No provider message or production deployment occurred; external delivery remains unverified. Native Safari/WebKit, manual screen-reader/full zoom review and prior release gates remain open. The standalone single-user schema checker was extended; real PostgreSQL fresh-schema/catalog checks supplied the local schema evidence because host PostgreSQL binaries are absent.

## Next tasks and remaining gates

The [remaining-task register](remaining-tasks.md#m5-required-implementation-and-delivery-work) tracks these under **M5-01–M5-10**, with dependencies and completion evidence. The first slice is committed as `efd4fd6`. See the [current handoff](implementation-handoff.md) and [M6 preparation](m6-status.md); M6 acceptance does not waive the remaining M5 work.

1. M5-02 backend/desktop implementation is locally verified. Carry its small-screen checks to Claude and provider receipt to M5-09; preserve the [recipient/access contract](m5-onboarding-lifecycle-contract.md). M5-01 remains locally verified.
2. M5-03 backend/desktop foundation is locally verified; carry small-screen checks to Claude. Implement M5-04 reminder/digest consumers and notification delivery using the [persisted schedule contract](m5-schedules-contract.md).
3. Add approval inbox, follow-up drafts, logged-reply cancellation and approval invalidation, plus rate budgets and optional AI off-path behavior.
4. Complete crash/restart/outage, DST, operator reconciliation and external recipient-controlled delivery acceptance. Preserve M1–M4 release gates and the mandatory UI standards.

M5 is not complete until its required notification inventory and automation gates pass. The first slice does not settle precise future lifecycle recipients, digest cadence or uncertain-delivery operator permissions.

## Four-space formatting follow-up

The user's subsequent request changes Prettier to four-space indentation for Vue as well as other supported frontend files. The Vue-only two-space override was removed, all 28 Vue files were formatted with pinned Prettier and contributor guidance was updated. See the [follow-up validation](evidence/m5/four-space-formatting/README.md). Existing milestone tasks and release gates remain tracked in [remaining tasks](remaining-tasks.md).

## Required app-wide confirmation follow-up

**Confirmed UI-01:** shared native modal confirmations now cover destructive actions and access revocations across milestones, including both partnership portals and invitation email cancellation. See [implementation and verification](evidence/confirmation-modals/README.md) for pinned formatting/types/build, 26 component tests, affected desktop/mobile journeys, Axe checks and inspected screenshots. The follow-up also closes M1-06's workspace-link target size finding. M5's remaining implementation and inherited release gates stay open in the [register](remaining-tasks.md).
